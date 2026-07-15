<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Events\SaleLocked;
use App\Events\SaleUnlocked;
use App\Events\SaleUpdated;
use App\Exceptions\SaleItemEditNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\PhoneNormalizer;
use App\Services\RecalculateClosedShifts;
use App\Services\SaleItemEditor;
use App\Services\WhatsappMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Enum;

class SaleController extends Controller
{
    /**
     * Crea una venta manual desde la mesa (solo admin-sucursal, paridad con
     * Sucursal\WorkbenchController::store). Cada línea se arma con SaleItemEditor
     * (misma lógica de presentación/peso, snapshot y recálculo que la edición
     * de items). El precio es el de catálogo/presentación, con override opcional
     * del admin. La venta nace Active, origin='admin', sin cobros.
     */
    public function store(Request $request, SaleItemEditor $editor): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($request, 'Solo el administrador de sucursal puede crear ventas.');
        app()->instance('tenant', $user->tenant);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.presentation_id' => 'nullable|integer',
            'items.*.custom_price' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        $branchId = $user->branch_id;

        // Valida que todos los productos existan, estén activos y sean de la
        // sucursal, y precarga presentaciones para resolver precios.
        $productIds = collect($validated['items'])->pluck('product_id')->unique();
        $products = Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('id', $productIds)
            ->with('presentations')
            ->get()
            ->keyBy('id');

        if ($productIds->diff($products->keys())->isNotEmpty()) {
            return response()->json(['message' => 'Algunos productos no son válidos o están inactivos.'], 422);
        }

        try {
            $sale = DB::transaction(function () use ($validated, $branchId, $user, $products, $editor) {
                // Folio atómico por sucursal (mismo esquema S-00001 que la web).
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchId]);
                $count = Sale::withoutGlobalScopes()->where('branch_id', $branchId)->count();

                $sale = Sale::create([
                    'tenant_id' => $user->tenant_id,
                    'branch_id' => $branchId,
                    'folio' => 'S-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT),
                    'payment_method' => 'cash',
                    'total' => 0,
                    'amount_paid' => 0,
                    'amount_pending' => 0,
                    'origin' => 'admin',
                    'origin_name' => 'Administrador',
                    'status' => SaleStatus::Active,
                ]);

                foreach ($validated['items'] as $item) {
                    $product = $products[$item['product_id']];
                    $presentationId = $item['presentation_id'] ?? null;

                    // Precio base: presentación o catálogo; override del admin si viene.
                    $basePrice = (float) $product->price;
                    if ($presentationId && in_array($product->sale_mode, ['presentation', 'both'], true)) {
                        $pres = $product->presentations->firstWhere('id', $presentationId);
                        if ($pres) {
                            $basePrice = (float) $pres->price;
                        }
                    }
                    $unitPrice = isset($item['custom_price']) && $item['custom_price'] !== null
                        ? (float) $item['custom_price']
                        : $basePrice;

                    $editor->add($sale, [
                        'product_id' => $product->id,
                        'presentation_id' => $presentationId,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'notes' => $item['notes'] ?? null,
                    ], null, $user);
                }

                return $sale;
            });
        } catch (SaleItemEditNotAllowed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->broadcast($sale);

        return $this->saleResponse($sale->refresh(), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|in:active,pending,all']);

        $branchId = $request->user()->branch_id;
        $base = fn () => Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereIn('status', [SaleStatus::Active, SaleStatus::Pending]);

        $counts = [
            'active' => $base()->where('status', SaleStatus::Active)->count(),
            'pending' => $base()->where('status', SaleStatus::Pending)->count(),
        ];
        $counts['all'] = $counts['active'] + $counts['pending'];

        $filter = $request->input('status', 'all');
        $query = $base();
        if ($filter === 'active') {
            $query->where('status', SaleStatus::Active);
        } elseif ($filter === 'pending') {
            $query->where('status', SaleStatus::Pending);
        }

        $sales = $query->with(['items', 'customer:id,name,phone', 'lockedByUser:id,name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => HubSaleResource::collection($sales)->resolve($request),
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, int $sale): HubSaleResource
    {
        $found = $this->findSale($request, $sale);
        $found->load(['items', 'payments.user:id,name', 'payments.updatedByUser:id,name', 'customer', 'lockedByUser:id,name']);

        $branch = Branch::withoutGlobalScopes()->find($request->user()->branch_id);

        // Los métodos de pago habilitados de la sucursal viajan junto al detalle
        // para que el hub no los hardcodee (evita ofrecer un método que el
        // backend rechazaría con 422). branch alimenta el ticket de impresión.
        return HubSaleResource::make($found)->additional([
            'payment_methods' => $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'],
            'branch' => $branch ? [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
            ] : null,
            // Edición de items (solo admin): la UI marca el motivo como
            // obligatorio si la sucursal lo exige (paridad web).
            'can_edit_items' => $request->user()->hasRole('admin-sucursal') || $request->user()->hasRole('superadmin'),
            'sale_item_edit_reason_mode' => $branch?->sale_item_edit_reason_mode ?? 'optional',
        ]);
    }

    /**
     * Pausar/reactivar una venta. El cajero solo puede Active <-> Pending.
     */
    public function updateStatus(Request $request, int $sale): JsonResponse
    {
        $found = $this->findSale($request, $sale);

        $validated = $request->validate([
            'status' => ['required', new Enum(SaleStatus::class)],
        ]);
        $target = SaleStatus::from($validated['status']);

        if (! in_array($target, [SaleStatus::Active, SaleStatus::Pending], true)) {
            return response()->json(['message' => 'No tienes permiso para esta transición.'], 403);
        }
        if (! $found->status->canTransitionTo($target)) {
            return response()->json([
                'message' => "No se puede cambiar de {$found->status->label()} a {$target->label()}.",
            ], 422);
        }

        $found->update(['status' => $target]);
        $this->broadcast($found);

        return $this->saleResponse($found->refresh());
    }

    /**
     * Solicita la cancelación de una venta (la aprueba después un admin).
     */
    public function requestCancel(Request $request, int $sale): JsonResponse
    {
        $found = $this->findSale($request, $sale);

        if ($found->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'Esta venta ya está cancelada.'], 422);
        }
        if ($found->cancel_requested_at) {
            return response()->json(['message' => 'Ya existe una solicitud de cancelación.'], 422);
        }

        $validated = $request->validate([
            'cancel_request_reason' => 'required|string|max:500',
        ]);

        $found->update([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $request->user()->id,
            'cancel_request_reason' => $validated['cancel_request_reason'],
        ]);

        return $this->saleResponse($found->refresh());
    }

    /**
     * Cancelación DIRECTA (solo admin-sucursal): borra pagos, marca Cancelled y
     * recalcula cortes cerrados afectados. Paridad con WorkbenchController::cancel.
     */
    public function cancel(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($request, 'No tienes permiso para cancelar ventas.');

        $found = $this->findSale($request, $sale);

        if ($found->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'Esta venta ya está cancelada.'], 422);
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $wasCompleted = $found->status === SaleStatus::Completed;

        DB::transaction(function () use ($found, $user, $validated, $wasCompleted) {
            $found->payments()->delete();

            $found->update([
                'status' => SaleStatus::Cancelled,
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $validated['cancel_reason'],
            ]);

            if ($wasCompleted) {
                app(RecalculateClosedShifts::class)->forSale($found);
            }
        });

        $this->broadcast($found);

        return $this->saleResponse($found->refresh());
    }

    /**
     * Reabre una venta completada (Completed → Active, solo admin-sucursal).
     * Paridad con WorkbenchController::reopen.
     */
    public function reopen(Request $request, int $sale): JsonResponse
    {
        $this->ensureAdmin($request, 'No tienes permiso para reabrir ventas.');

        $found = $this->findSale($request, $sale);

        if ($found->status !== SaleStatus::Completed) {
            return response()->json(['message' => 'Solo se pueden enviar a pendiente ventas completadas.'], 422);
        }

        $totalPaid = $found->payments()->sum('amount');
        $pending = round((float) $found->total - $totalPaid, 2);

        $found->update([
            'status' => SaleStatus::Active,
            'amount_pending' => max($pending, 0),
            'completed_at' => null,
        ]);

        $this->broadcast($found);

        return $this->saleResponse($found->refresh());
    }

    /**
     * Asigna o desasigna (customer_id null) un cliente existente a la venta y
     * aplica precios preferenciales. Reusa el servicio de la web.
     */
    public function assignCustomer(Request $request, int $sale, AssignCustomerToSale $service): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $found = $this->findSale($request, $sale);

        if ($found->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'No se puede asignar cliente a una venta cancelada.'], 422);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $result = $service->execute($found, $validated['customer_id'] ?? null, $user->branch_id);

        return response()->json([
            'data' => HubSaleResource::make($found->refresh()->load(['items', 'payments', 'customer']))->resolve($request),
            'had_payments' => $result['had_payments'],
            'skipped_piece_presentations' => array_values(array_unique($result['skipped_piece_presentations'])),
        ]);
    }

    /**
     * Link wa.me de la venta (cliente o contact_phone). reason=needs_phone si no
     * hay teléfono → el hub pide capturarlo con storeWhatsappPhone.
     */
    public function whatsappLink(Request $request, int $sale, WhatsappMessageService $whatsapp): JsonResponse
    {
        $found = $this->findSale($request, $sale);

        return response()->json($whatsapp->linkForSale($found));
    }

    /**
     * Guarda el teléfono capturado (10 dígitos → E.164) en contact_phone y
     * devuelve el link. No crea cliente.
     */
    public function storeWhatsappPhone(Request $request, int $sale, WhatsappMessageService $whatsapp): JsonResponse
    {
        $found = $this->findSale($request, $sale);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d{10}$/'],
        ], [
            'phone.regex' => 'El teléfono debe tener 10 dígitos.',
            'phone.required' => 'Ingresa un teléfono.',
        ]);

        $found->update(['contact_phone' => PhoneNormalizer::normalize($validated['phone'])]);

        return response()->json($whatsapp->linkForSale($found->fresh()));
    }

    /**
     * Quita el teléfono de WhatsApp guardado en la venta (paridad con
     * Sucursal/Caja WorkbenchController@destroyWhatsappPhone).
     */
    public function destroyWhatsappPhone(Request $request, int $sale): JsonResponse
    {
        $found = $this->findSale($request, $sale);

        $found->update(['contact_phone' => null]);

        return response()->json(['ok' => true]);
    }

    /** Adquiere el lock de concurrencia (5 min). 409 si otro la tiene. */
    public function lock(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();
        $this->findSale($request, $sale); // 404 si no es de la sucursal

        return DB::transaction(function () use ($sale, $user) {
            $locked = Sale::withoutGlobalScopes()->lockForUpdate()->find($sale);

            if ($locked->locked_by && $locked->locked_by !== $user->id
                && $locked->locked_at && $locked->locked_at->diffInMinutes(now()) < 5) {
                return response()->json([
                    'locked' => true,
                    'locked_by_name' => $locked->lockedByUser?->name ?? 'Otro usuario',
                ], 409);
            }

            // Un usuario solo mantiene un lock: libera los demás.
            Sale::withoutGlobalScopes()->where('locked_by', $user->id)->where('id', '!=', $locked->id)->get()
                ->each(function (Sale $prev) {
                    $prev->updateQuietly(['locked_by' => null, 'locked_at' => null]);
                    $this->dispatchEvent(fn () => SaleUnlocked::dispatch($prev->id, $prev->branch_id));
                });

            $locked->updateQuietly(['locked_by' => $user->id, 'locked_at' => now()]);
            $this->dispatchEvent(fn () => SaleLocked::dispatch($locked->id, $locked->branch_id, $user->id, $user->name));

            return response()->json(['ok' => true]);
        });
    }

    public function unlock(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();
        $found = $this->findSale($request, $sale);

        if ($found->locked_by === $user->id) {
            $found->updateQuietly(['locked_by' => null, 'locked_at' => null]);
            $this->dispatchEvent(fn () => SaleUnlocked::dispatch($found->id, $found->branch_id));
        }

        return response()->json(['ok' => true]);
    }

    public function heartbeat(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();
        $found = $this->findSale($request, $sale);

        if ($found->locked_by === $user->id) {
            $found->updateQuietly(['locked_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** Guard de admin-sucursal para las potestades de gestión de ventas. */
    private function ensureAdmin(Request $request, string $message): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            $message
        );
    }

    /** Venta de la sucursal del token; cross-branch → 404. */
    private function findSale(Request $request, int $sale): Sale
    {
        return Sale::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->findOrFail($sale);
    }

    private function saleResponse(Sale $sale, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => HubSaleResource::make($sale->load(['items', 'payments.user:id,name', 'payments.updatedByUser:id,name', 'customer']))->resolve(request()),
        ], $status);
    }

    private function broadcast(Sale $sale): void
    {
        $this->dispatchEvent(fn () => SaleUpdated::dispatch($sale->fresh()));
    }

    /** Dispara un evento tolerando que el broadcasting (Reverb) esté caído. */
    private function dispatchEvent(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            Log::warning('Hub sale event dispatch failed', ['error' => $e->getMessage()]);
        }
    }
}
