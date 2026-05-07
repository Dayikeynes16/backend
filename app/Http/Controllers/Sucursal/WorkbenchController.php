<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\PhoneNormalizer;
use App\Services\RecalculateClosedShifts;
use App\Services\WhatsappMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class WorkbenchController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $sales = Sale::where('branch_id', $branchId)
            ->whereIn('status', [SaleStatus::Active, SaleStatus::Pending])
            ->with(['items', 'payments', 'lockedByUser:id,name', 'customer:id,name,phone'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->with(['category', 'presentations' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        $categories = Category::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $paymentMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        return Inertia::render('Sucursal/Workbench', [
            'sales' => $sales,
            'products' => $products,
            'categories' => $categories,
            'tenant' => app('tenant'),
            'branchId' => $branchId,
            'branchInfo' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'ticket_config' => $branch->ticket_config,
            ],
            'paymentMethods' => $paymentMethods,
            'canCreate' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'canCancel' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'canManageStatus' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'canEditPayments' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'canEditPrice' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'customers' => Schema::hasTable('customers')
                ? Customer::where('branch_id', $branchId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'phone'])
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para crear ventas.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.presentation_id' => 'nullable|integer',
            'items.*.custom_price' => 'nullable|numeric|min:0',
        ]);

        $branchId = $user->branch_id;
        $tenantId = $user->tenant_id;

        $productIds = collect($request->items)->pluck('product_id')->unique();
        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('id', $productIds)
            ->with('presentations')
            ->get()
            ->keyBy('id');

        $missing = $productIds->diff($products->keys());
        if ($missing->isNotEmpty()) {
            return back()->with('error', 'Algunos productos no son validos.');
        }

        DB::transaction(function () use ($request, $branchId, $tenantId, $products, $user) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchId]);

            $count = Sale::withoutGlobalScopes()->where('branch_id', $branchId)->count();
            $folio = 'S-'.str_pad($count + 1, 5, '0', STR_PAD_LEFT);

            $total = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = $products[$item['product_id']];
                $quantity = (float) $item['quantity'];
                $presentation = null;

                if (in_array($product->sale_mode, ['presentation', 'both'], true) && ! empty($item['presentation_id'])) {
                    $presentation = $product->presentations->find($item['presentation_id']);
                }

                if ($presentation) {
                    // Presentation line: quantity = number of presentations sold.
                    // unit_type/quantity_unit = 'unit' (canonical for "N presentaciones").
                    $catalogPrice = (float) $presentation->price;
                    $productName = $product->name.' - '.$presentation->name;
                    $unitTypeToPersist = 'unit';
                    $quantityUnit = 'unit';
                    $saleModeAtSale = 'presentation';
                    $presentationSnapshot = $this->snapshotPresentation($presentation);
                    $presentationIdToPersist = $presentation->id;
                } else {
                    // Weight / piece line: unit_type follows product semantics.
                    // 'weight'/'both' without presentation → kg, otherwise product's unit_type.
                    $catalogPrice = (float) $product->price;
                    $productName = $product->name;
                    $unitTypeToPersist = (in_array($product->sale_mode, ['weight', 'both'], true))
                        ? 'kg'
                        : $product->unit_type;
                    $quantityUnit = $unitTypeToPersist;
                    $saleModeAtSale = ($product->sale_mode === 'weight' || ($product->sale_mode === 'both'))
                        ? 'weight'
                        : 'piece';
                    $presentationSnapshot = null;
                    $presentationIdToPersist = null;
                }

                $unitPrice = $catalogPrice;

                // Allow admin override of unit price
                if (isset($item['custom_price']) && $item['custom_price'] !== null
                    && ($user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'))) {
                    $unitPrice = (float) $item['custom_price'];
                }

                $subtotal = round($quantity * $unitPrice, 2);
                $total += $subtotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'presentation_id' => $presentationIdToPersist,
                    'product_name' => $productName,
                    'unit_type' => $unitTypeToPersist,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'original_unit_price' => $catalogPrice,
                    'subtotal' => $subtotal,
                    'presentation_snapshot' => $presentationSnapshot,
                    'sale_mode_at_sale' => $saleModeAtSale,
                    'quantity_unit' => $quantityUnit,
                ];
            }

            $sale = Sale::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'folio' => $folio,
                'total' => round($total, 2),
                'amount_paid' => 0,
                'amount_pending' => round($total, 2),
                'origin' => 'admin',
                'origin_name' => 'Administrador',
                'status' => SaleStatus::Active,
            ]);

            foreach ($itemsData as $data) {
                $sale->items()->create($data);
            }
        });

        return back()->with('success', 'Venta creada.');
    }

    /**
     * Frozen snapshot of a presentation at sale time. Persisted in
     * sale_items.presentation_snapshot so the line stays interpretable
     * even if the catalog presentation is later edited or deleted.
     */
    private function snapshotPresentation(ProductPresentation $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'content' => (float) $p->content,
            'unit' => $p->unit,
            'price' => (float) $p->price,
        ];
    }

    public function reopen(Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para reabrir ventas.');
        }

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status !== SaleStatus::Completed) {
            return back()->with('error', 'Solo se pueden enviar a pendiente ventas completadas.');
        }

        $totalPaid = $sale->payments()->sum('amount');
        $pending = round((float) $sale->total - $totalPaid, 2);

        $sale->update([
            'status' => SaleStatus::Active,
            'amount_pending' => max($pending, 0),
            'completed_at' => null,
        ]);

        return back()->with('success', "Venta {$sale->folio} enviada a pendiente.");
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para cancelar ventas.');
        }

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === SaleStatus::Cancelled) {
            return back()->with('error', 'Esta venta ya esta cancelada.');
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $wasCompleted = $sale->status === SaleStatus::Completed;

        DB::transaction(function () use ($sale, $user, $validated, $wasCompleted) {
            // Soft-delete associated payments and reset amounts
            $sale->payments()->delete();

            $sale->update([
                'status' => SaleStatus::Cancelled,
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $validated['cancel_reason'],
            ]);

            // Auto-recalculate affected closed shifts
            if ($wasCompleted) {
                $this->recalculateAffectedShifts($sale);
            }
        });

        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        $msg = "Venta {$sale->folio} cancelada.";
        if ($wasCompleted) {
            $msg .= ' Los cortes de caja afectados fueron recalculados.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Recalculate any closed shifts that included payments from a cancelled sale.
     * Delega al servicio compartido para mantener consistencia con close().
     */
    private function recalculateAffectedShifts(Sale $sale): void
    {
        app(RecalculateClosedShifts::class)->forSale($sale);
    }

    public function requestCancel(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === SaleStatus::Cancelled) {
            return back()->with('error', 'Esta venta ya esta cancelada.');
        }

        if ($sale->cancel_requested_at) {
            return back()->with('error', 'Ya existe una solicitud de cancelacion para esta venta.');
        }

        $validated = $request->validate([
            'cancel_request_reason' => 'required|string|max:500',
        ]);

        $sale->update([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $user->id,
            'cancel_request_reason' => $validated['cancel_request_reason'],
        ]);

        return back()->with('success', "Solicitud de cancelacion enviada para {$sale->folio}.");
    }

    public function updateStatus(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para cambiar el estado de ventas.');
        }

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', new Enum(SaleStatus::class)],
            'cancel_reason' => 'required_if:status,cancelled|nullable|string|max:500',
        ]);

        $targetStatus = SaleStatus::from($validated['status']);

        if (! $sale->status->canTransitionTo($targetStatus)) {
            return back()->with('error', "No se puede cambiar de {$sale->status->label()} a {$targetStatus->label()}.");
        }

        // Check if locked by another user
        if ($sale->isLockedBy($user->id) && $sale->locked_by !== $user->id && $sale->locked_by !== null) {
            return back()->with('error', 'Esta venta esta siendo editada por otro usuario.');
        }

        return match ($targetStatus) {
            SaleStatus::Cancelled => $this->performCancel($sale, $user, $validated['cancel_reason']),
            SaleStatus::Active => $this->performReactivate($sale, $user),
            SaleStatus::Pending => $this->performPause($sale),
            default => back()->with('error', 'Transicion no soportada.'),
        };
    }

    private function performCancel(Sale $sale, $user, string $reason): RedirectResponse
    {
        $wasCompleted = $sale->status === SaleStatus::Completed;

        DB::transaction(function () use ($sale, $user, $reason, $wasCompleted) {
            $sale->payments()->delete();

            $sale->update([
                'status' => SaleStatus::Cancelled,
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            if ($wasCompleted) {
                $this->recalculateAffectedShifts($sale);
            }
        });

        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        $msg = "Venta {$sale->folio} cancelada.";
        if ($wasCompleted) {
            $msg .= ' Los cortes de caja afectados fueron recalculados.';
        }

        return back()->with('success', $msg);
    }

    private function performReactivate(Sale $sale, $user): RedirectResponse
    {
        if ($sale->status === SaleStatus::Completed) {
            $totalPaid = $sale->payments()->sum('amount');
            $pending = round((float) $sale->total - $totalPaid, 2);

            $sale->update([
                'status' => SaleStatus::Active,
                'amount_pending' => max($pending, 0),
                'completed_at' => null,
            ]);
        } else {
            // From pending → active
            $sale->update(['status' => SaleStatus::Active]);
        }

        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', "Venta {$sale->folio} reactivada.");
    }

    private function performPause(Sale $sale): RedirectResponse
    {
        $sale->update(['status' => SaleStatus::Pending]);

        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', "Venta {$sale->folio} marcada como pendiente.");
    }

    /**
     * Construye el link wa.me al vuelo con el detalle de la venta.
     * Se calcula on-demand (no inflamos el payload inicial del workbench con URLs
     * largas). El frontend llama este endpoint en el click del botón para que
     * window.open vaya dentro del gesto del usuario y no lo bloquee el navegador.
     *
     * Resolución del teléfono: primero el del cliente asignado; si no existe, el
     * `contact_phone` que se haya capturado previamente en la venta. Cuando no
     * hay ninguno, se devuelve `reason: needs_phone` para que el frontend abra
     * el modal de captura.
     */
    public function whatsappLink(Sale $sale, WhatsappMessageService $whatsappService): JsonResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403, 'Esta venta no pertenece a tu sucursal.');
        }
        if ($sale->tenant_id !== $user->tenant_id) {
            abort(403, 'Esta venta no pertenece a tu empresa.');
        }

        return response()->json($whatsappService->linkForSale($sale));
    }

    /**
     * Guarda el teléfono capturado en `contact_phone` (E.164) y devuelve el link.
     * No crea cliente — el dato queda en la venta para futuros envíos y para
     * que en el futuro se pueda cruzar con clientes que se den de alta con
     * el mismo número.
     */
    public function storeWhatsappPhone(Request $request, Sale $sale, WhatsappMessageService $whatsappService): JsonResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403, 'Esta venta no pertenece a tu sucursal.');
        }
        if ($sale->tenant_id !== $user->tenant_id) {
            abort(403, 'Esta venta no pertenece a tu empresa.');
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d{10}$/'],
        ], [
            'phone.regex' => 'El teléfono debe tener 10 dígitos.',
            'phone.required' => 'Ingresa un teléfono.',
        ]);

        $sale->update([
            'contact_phone' => PhoneNormalizer::normalize($validated['phone']),
        ]);

        return response()->json($whatsappService->linkForSale($sale->fresh()));
    }

    /**
     * Quita el teléfono manual (`contact_phone`) de la venta. No afecta al
     * teléfono del cliente asignado — eso vive en el módulo de clientes.
     */
    public function destroyWhatsappPhone(Sale $sale): JsonResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403, 'Esta venta no pertenece a tu sucursal.');
        }
        if ($sale->tenant_id !== $user->tenant_id) {
            abort(403, 'Esta venta no pertenece a tu empresa.');
        }

        $sale->update(['contact_phone' => null]);

        return response()->json(['ok' => true]);
    }

    public function assignCustomer(Request $request, Sale $sale, AssignCustomerToSale $service): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403);
        }

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === SaleStatus::Cancelled) {
            return back()->with('error', 'No se puede asignar cliente a una venta cancelada.');
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $customerId = $validated['customer_id'];
        $result = $service->execute($sale, $customerId, $user->branch_id);

        $msg = $customerId
            ? "Cliente asignado a venta {$sale->folio}."
            : "Cliente removido de venta {$sale->folio}.";

        if ($result['had_payments']) {
            $msg .= ' La venta tenia pagos registrados. Verifica que los montos sean correctos.';
        }

        if (! empty($result['skipped_piece_presentations'])) {
            $names = implode(', ', array_unique($result['skipped_piece_presentations']));
            $msg .= " Precio preferencial no aplicado a presentaciones por pieza sin equivalencia en kg/l: {$names}.";
        }

        return back()->with('success', $msg);
    }
}
