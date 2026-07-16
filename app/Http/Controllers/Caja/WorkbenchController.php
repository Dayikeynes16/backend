<?php

namespace App\Http\Controllers\Caja;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Exceptions\OrderLink\CrossBranchLinkException;
use App\Exceptions\OrderLink\IneligibleScaleSaleException;
use App\Exceptions\OrderLink\IneligibleWebOrderException;
use App\Exceptions\OrderLink\LockedScaleSaleException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\OrderLinkService;
use App\Services\PhoneNormalizer;
use App\Services\WhatsappMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class WorkbenchController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();

        $hasShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if (! $hasShift) {
            return redirect()->route('caja.turno', app('tenant')->slug);
        }

        $sales = Sale::where('branch_id', $user->branch_id)
            ->whereIn('status', [SaleStatus::Active, SaleStatus::Pending])
            ->with([
                'items', 'payments', 'lockedByUser:id,name', 'customer:id,name,phone',
                'linkedOrder:id,folio,status',
                'fulfilledBy:id,folio,status,linked_order_id',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $paymentMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        // Lista para asignar cliente a una venta. El cajero solo puede *asignar*
        // clientes existentes — no crear/editar/eliminar (ese CRUD vive en el
        // módulo de Sucursal y está protegido por roles).
        $customers = Customer::where('branch_id', $user->branch_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        return Inertia::render('Caja/Workbench', [
            'sales' => $sales,
            'tenant' => app('tenant'),
            'branchId' => $user->branch_id,
            'branchInfo' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'ticket_config' => $branch->ticket_config,
                'payment_receipts_enabled' => (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required),
                'payment_receipts_required' => (bool) $branch->payment_receipts_required,
            ],
            'paymentMethods' => $paymentMethods,
            'customers' => $customers,
        ]);
    }

    public function updateStatus(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', new Enum(SaleStatus::class)],
        ]);

        $targetStatus = SaleStatus::from($validated['status']);

        // Cajero solo puede: Active <-> Pending
        if (! in_array($targetStatus, [SaleStatus::Active, SaleStatus::Pending])) {
            return back()->with('error', 'No tienes permiso para esta transicion.');
        }

        if (! $sale->status->canTransitionTo($targetStatus)) {
            return back()->with('error', "No se puede cambiar de {$sale->status->label()} a {$targetStatus->label()}.");
        }

        // Lock check
        if ($sale->locked_by && $sale->locked_by !== $user->id && $sale->locked_at > now()->subMinutes(5)) {
            return back()->with('error', 'Esta venta esta siendo operada por otro usuario.');
        }

        $sale->update(['status' => $targetStatus]);
        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        $msg = $targetStatus === SaleStatus::Pending
            ? "Venta {$sale->folio} marcada como pendiente."
            : "Venta {$sale->folio} reactivada.";

        return back()->with('success', $msg);
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
            return back()->with('error', 'Ya existe una solicitud de cancelacion.');
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

    /**
     * Asigna o desasigna un cliente existente a la venta. El cajero NO puede
     * crear, editar ni eliminar clientes — eso vive en el módulo de Sucursal.
     * Aquí solo se permite seleccionar uno ya existente para que se apliquen
     * sus precios preferenciales y para tener el teléfono asociado.
     */
    public function assignCustomer(Request $request, Sale $sale, AssignCustomerToSale $service): RedirectResponse
    {
        $user = Auth::user();

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

    /**
     * Devuelve el link wa.me de la venta usando customer.phone o contact_phone.
     * Si no hay teléfono, retorna `reason: needs_phone` para que el frontend
     * abra el modal de captura.
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
     * No crea cliente.
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
     * Quita el teléfono manual (`contact_phone`) de la venta.
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

    /**
     * Vincula la venta de báscula con un pedido web pendiente. Espejo del
     * endpoint en Sucursal/WorkbenchController para que el cajero pueda
     * emparejar desde su pantalla.
     */
    public function linkOrder(Request $request, Sale $sale, OrderLinkService $service): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403, 'Esta venta no pertenece a tu sucursal.');
        }
        if ($sale->tenant_id !== $user->tenant_id) {
            abort(403, 'Esta venta no pertenece a tu empresa.');
        }

        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:sales,id'],
        ]);

        $webOrder = Sale::findOrFail($validated['order_id']);

        try {
            $service->link($sale, $webOrder);
        } catch (IneligibleScaleSaleException|IneligibleWebOrderException|CrossBranchLinkException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Venta vinculada al pedido {$webOrder->folio}.");
    }

    /**
     * Desvincula la venta de báscula del pedido web. Espejo del endpoint de
     * Sucursal/WorkbenchController.
     */
    public function unlinkOrder(Sale $sale, OrderLinkService $service): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403, 'Esta venta no pertenece a tu sucursal.');
        }
        if ($sale->tenant_id !== $user->tenant_id) {
            abort(403, 'Esta venta no pertenece a tu empresa.');
        }

        try {
            $service->unlink($sale);
        } catch (LockedScaleSaleException|IneligibleScaleSaleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pedido desvinculado.');
    }

    /**
     * Ventas de báscula elegibles para emparejar — espejo del endpoint
     * Sucursal/WorkbenchController::linkableSales para que el cajero pueda
     * vincular desde el banner del pedido web.
     */
    public function linkableSales(): JsonResponse
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $sales = Sale::where('branch_id', $branchId)
            ->where('origin', '!=', 'web')
            ->where('status', SaleStatus::Active)
            ->whereNull('linked_order_id')
            ->with(['items:id,sale_id,product_name,quantity,unit_type'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Sale $s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'created_at' => $s->created_at->toIso8601String(),
                'origin' => $s->origin,
                'origin_name' => $s->origin_name,
                'total' => (float) $s->total,
                'items_count' => $s->items->count(),
                'items_preview' => $s->items->take(3)->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'quantity' => (float) $i->quantity,
                    'unit_type' => $i->unit_type,
                ])->values(),
            ]);

        return response()->json(['sales' => $sales]);
    }

    /**
     * Pedidos web pendientes de la sucursal del cajero. Espejo del endpoint del
     * Sucursal/WorkbenchController; se usa para poblar el modal "Vincular pedido
     * web" desde la pantalla de Caja.
     */
    public function pendingWebOrders(): JsonResponse
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $orders = Sale::where('branch_id', $branchId)
            ->where('origin', 'web')
            ->where('status', SaleStatus::Pending)
            ->with(['items:id,sale_id,product_name,quantity,unit_type'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Sale $s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'created_at' => $s->created_at->toIso8601String(),
                'contact_name' => $s->contact_name,
                'contact_phone' => $s->contact_phone,
                'delivery_type' => $s->delivery_type,
                'delivery_address' => $s->delivery_address,
                'delivery_fee' => $s->delivery_fee !== null ? (float) $s->delivery_fee : null,
                'total' => (float) $s->total,
                'items_count' => $s->items->count(),
                'items_preview' => $s->items->take(3)->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'quantity' => (float) $i->quantity,
                    'unit_type' => $i->unit_type,
                ])->values(),
            ]);

        return response()->json(['orders' => $orders]);
    }
}
