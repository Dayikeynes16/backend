<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\PurchaseStatus;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints de lectura del detalle de un proveedor (resumen, compras, pagos,
 * productos) compartidos entre Empresa y Sucursal. La única diferencia entre
 * roles es el alcance por sucursal:
 *  - Empresa: ve compras/pagos/deuda de TODAS las sucursales del tenant.
 *  - Sucursal: override de branchIdForProviderDetail() → sólo su sucursal.
 *
 * No muta nada: los montos almacenados (amount_paid/amount_pending) los
 * mantiene PurchasePaymentService; aquí sólo se leen para que el detalle cuadre
 * con el listado de Compras. Las compras canceladas y los pagos cancelados se
 * excluyen de todos los totales.
 */
trait HandlesProviderDetail
{
    use SerializesPurchases;

    /**
     * Override en Sucursal para devolver $user->branch_id y restringir el
     * detalle a su propia sucursal. Empresa no lo restringe (null).
     */
    protected function branchIdForProviderDetail(): ?int
    {
        return null;
    }

    /**
     * GET .../proveedores/{provider}/resumen — KPIs del periodo + deuda actual.
     */
    public function resumen(Request $request, Provider $provider): JsonResponse
    {
        $this->assertProviderVisible($provider);
        [$from, $to] = $this->providerDetailRange($request);
        $branchId = $this->branchIdForProviderDetail();

        $totalComprado = (float) $this->providerPurchasesBase($provider)
            ->whereDate('purchased_at', '>=', $from)
            ->whereDate('purchased_at', '<=', $to)
            ->sum('total');

        $comprasCount = (int) $this->providerPurchasesBase($provider)
            ->whereDate('purchased_at', '>=', $from)
            ->whereDate('purchased_at', '<=', $to)
            ->count();

        $totalPagado = (float) ProviderPayment::query()
            ->where('provider_id', $provider->id)
            ->whereNull('cancelled_at')
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->whereDate('paid_at', '>=', $from)
            ->whereDate('paid_at', '<=', $to)
            ->sum('amount');

        // Deuda actual: saldo corriente, TODO el histórico (no filtrado por fecha).
        $deudaActual = (float) $this->providerPurchasesBase($provider)
            ->where('amount_pending', '>', 0)
            ->sum('amount_pending');

        $ultima = $this->providerPurchasesBase($provider)
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->first(['id', 'folio', 'total', 'purchased_at']);

        return response()->json([
            'range' => ['from' => $from, 'to' => $to],
            'total_comprado' => round($totalComprado, 2),
            'total_pagado' => round($totalPagado, 2),
            'compras_count' => $comprasCount,
            'deuda_actual' => round($deudaActual, 2),
            'ultima_compra' => $ultima ? [
                'id' => $ultima->id,
                'folio' => $ultima->folio,
                'total' => (float) $ultima->total,
                'purchased_at' => $ultima->purchased_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * GET .../proveedores/{provider}/compras — compras del periodo, paginadas y
     * serializadas igual que el listado de Compras (reusa CompraDetailModal).
     */
    public function compras(Request $request, Provider $provider): JsonResponse
    {
        $this->assertProviderVisible($provider);
        [$from, $to] = $this->providerDetailRange($request);

        $purchases = $this->providerPurchasesBase($provider)
            ->whereDate('purchased_at', '>=', $from)
            ->whereDate('purchased_at', '<=', $to)
            ->with(['provider:id,name', 'branch:id,name', 'creator:id,name', 'items', 'attachments', 'payments', 'history.user:id,name'])
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate($this->perPageFromRequest($request));

        $purchases->getCollection()->transform(fn (Purchase $p) => $this->serializePurchase($p));

        return response()->json($purchases);
    }

    /**
     * GET .../proveedores/{provider}/pagos — historial de pagos/abonos del
     * periodo (incluye pagos "a cuenta" y excedentes), paginado.
     */
    public function pagos(Request $request, Provider $provider): JsonResponse
    {
        $this->assertProviderVisible($provider);
        [$from, $to] = $this->providerDetailRange($request);
        $branchId = $this->branchIdForProviderDetail();

        $payments = ProviderPayment::query()
            ->where('provider_id', $provider->id)
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->whereDate('paid_at', '>=', $from)
            ->whereDate('paid_at', '<=', $to)
            ->with(['purchase:id,folio', 'user:id,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($this->perPageFromRequest($request));

        $payments->getCollection()->transform(fn (ProviderPayment $pay) => [
            'id' => $pay->id,
            'paid_at' => $pay->paid_at?->toIso8601String(),
            'amount' => (float) $pay->amount,
            'payment_method' => $pay->payment_method?->value,
            'reference' => $pay->reference,
            'notes' => $pay->notes,
            'purchase' => $pay->purchase ? ['id' => $pay->purchase->id, 'folio' => $pay->purchase->folio] : null,
            'cancelled_at' => $pay->cancelled_at?->toIso8601String(),
            'cancel_reason' => $pay->cancel_reason,
            'user' => $pay->user ? ['id' => $pay->user->id, 'name' => $pay->user->name] : null,
        ]);

        return response()->json($payments);
    }

    /**
     * GET .../proveedores/{provider}/productos — conceptos comprados al
     * proveedor en el periodo, agrupados por producto de catálogo.
     */
    public function productos(Request $request, Provider $provider): JsonResponse
    {
        $this->assertProviderVisible($provider);
        [$from, $to] = $this->providerDetailRange($request);
        $branchId = $this->branchIdForProviderDetail();

        $rows = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.provider_id', $provider->id)
            ->where('purchases.tenant_id', app('tenant')->id)
            ->where('purchases.status', '!=', PurchaseStatus::Cancelled->value)
            ->whereNull('purchases.deleted_at')
            ->when($branchId, fn ($q, $b) => $q->where('purchases.branch_id', $b))
            ->whereDate('purchases.purchased_at', '>=', $from)
            ->whereDate('purchases.purchased_at', '<=', $to)
            ->selectRaw('
                purchase_items.purchase_product_id,
                MAX(purchase_items.concept) as concept,
                MAX(purchase_items.unit) as unit,
                SUM(purchase_items.quantity) as total_quantity,
                SUM(purchase_items.subtotal) as total_amount,
                COUNT(DISTINCT purchase_items.purchase_id) as times_bought
            ')
            ->groupBy('purchase_items.purchase_product_id')
            ->orderByDesc('total_amount')
            ->limit(100)
            ->get();

        return response()->json([
            'items' => $rows->map(fn ($r) => [
                'purchase_product_id' => $r->purchase_product_id,
                'concept' => $r->concept,
                'unit' => $r->unit,
                'total_quantity' => round((float) $r->total_quantity, 3),
                'total_amount' => round((float) $r->total_amount, 2),
                'times_bought' => (int) $r->times_bought,
            ])->values(),
        ]);
    }

    /**
     * Snapshot inicial (deuda actual + última compra) para que la página de
     * detalle pinte al instante; el resto se carga lazy por rango.
     *
     * @return array<string, mixed>
     */
    protected function providerSeed(Provider $provider): array
    {
        $deuda = (float) $this->providerPurchasesBase($provider)
            ->where('amount_pending', '>', 0)
            ->sum('amount_pending');

        $count = (int) $this->providerPurchasesBase($provider)->count();

        $ultima = $this->providerPurchasesBase($provider)
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->first(['id', 'folio', 'total', 'purchased_at']);

        return [
            'deuda_actual' => round($deuda, 2),
            'compras_count' => $count,
            'ultima_compra' => $ultima ? [
                'id' => $ultima->id,
                'folio' => $ultima->folio,
                'total' => (float) $ultima->total,
                'purchased_at' => $ultima->purchased_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Query base de compras vivas del proveedor (sin canceladas), con el scope
     * de sucursal aplicado según el rol.
     */
    private function providerPurchasesBase(Provider $provider): Builder
    {
        return Purchase::query()
            ->where('provider_id', $provider->id)
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->when($this->branchIdForProviderDetail(), fn ($q, $b) => $q->where('branch_id', $b));
    }

    private function assertProviderVisible(Provider $provider): void
    {
        if ($provider->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    /**
     * @return array{0: string, 1: string} [from, to] en formato YYYY-MM-DD.
     */
    private function providerDetailRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = Carbon::parse($validated['from'] ?? now()->startOfMonth()->toDateString())->toDateString();
        $to = Carbon::parse($validated['to'] ?? now()->toDateString())->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function perPageFromRequest(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 20);

        return max(1, min($perPage, 100));
    }
}
