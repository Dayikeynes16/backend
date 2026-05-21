<?php

namespace App\Http\Controllers\Empresa;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de Compras para admin-empresa. Ve todas las sucursales del tenant.
 */
class PurchaseController extends Controller
{
    use HandlesPurchases;

    public function index(Request $request): Response
    {
        $tenant = app('tenant');

        // Por defecto se muestran las compras de HOY; el calendario del front
        // alterna el día enviando from/to.
        if (! $request->filled('from') && ! $request->filled('to')) {
            $today = now()->toDateString();
            $request->merge(['from' => $today, 'to' => $today]);
        }

        $query = Purchase::query()->with([
            'provider:id,name', 'branch:id,name',
            'items', 'attachments', 'payments',
        ]);
        $query = $this->applyBranchScopeToQuery($query);

        if ($branchFilter = $request->integer('branch_id')) {
            $query->where('branch_id', $branchFilter);
        }

        $query = $this->applyIndexFilters($query, $request);

        // KPIs sobre el MISMO conjunto filtrado (fecha, sucursal, proveedor…),
        // excluyendo canceladas — así las tarjetas cuadran con la tabla.
        $kpis = $this->kpisFromQuery((clone $query)->where('status', '!=', PurchaseStatus::Cancelled));

        // Devolvemos cada compra ya con items + attachments para que el detail
        // modal del frontend no necesite un endpoint adicional. 200 compras es
        // el límite duro; con ~5 líneas promedio el payload es manejable (~50 KB).
        $purchases = $query
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Purchase $p) => $this->serializePurchase($p));

        return Inertia::render('Empresa/Compras/Index', [
            'purchases' => $purchases,
            'filters' => [
                'q' => $request->input('q'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'branch_id' => $branchFilter,
                'provider_id' => $request->integer('provider_id'),
                'status' => $request->input('status', 'all'),
                'payment_status' => $request->input('payment_status'),
            ],
            'branches' => Branch::where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']),
            'providers' => Provider::where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']),
            'purchaseProducts' => PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
            'kpis' => $kpis,
        ]);
    }

    // ─── Hooks del trait ─────────────────────────────────────────────────

    protected function resolveBranchIdForWrite(Request $request): int
    {
        // admin-empresa elige libremente; el trait valida pertenencia al tenant.
        return (int) $request->input('branch_id');
    }

    protected function applyBranchScopeToQuery(Builder $query): Builder
    {
        // admin-empresa ve todas las sucursales.
        return $query;
    }

    protected function assertCanMutate(Purchase $purchase): void
    {
        if ($purchase->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    protected function redirectAfterWrite(Request $request, string $message): RedirectResponse
    {
        return redirect()
            ->route('empresa.compras.index', app('tenant')->slug)
            ->with('success', $message);
    }

    // ─── Helpers privados ────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'folio' => $p->folio,
            'invoice_number' => $p->invoice_number,
            'purchased_at' => $p->purchased_at?->toIso8601String(),
            'status' => $p->status->value,
            'provider' => $p->provider ? ['id' => $p->provider->id, 'name' => $p->provider->name] : null,
            'branch' => $p->branch ? ['id' => $p->branch->id, 'name' => $p->branch->name] : null,
            'total' => (float) $p->total,
            'amount_paid' => (float) $p->amount_paid,
            'amount_pending' => (float) $p->amount_pending,
            'payment_status' => $this->paymentStatusFor($p),
            'attachments_count' => (int) ($p->attachments_count ?? 0),
        ];
    }

    private function paymentStatusFor(Purchase $p): string
    {
        if ($p->status === PurchaseStatus::Cancelled) {
            return 'cancelled';
        }
        $paid = (float) $p->amount_paid;
        $total = (float) $p->total;
        if ($paid <= 0) {
            return 'pending';
        }
        if ($paid >= $total) {
            return 'paid';
        }

        return 'partial';
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * KPIs a partir de una query ya filtrada (fecha, sucursal, proveedor…).
     *
     * @return array<string, mixed>
     */
    private function kpisFromQuery(Builder $query): array
    {
        return [
            'total_amount' => (float) (clone $query)->sum('total'),
            'count' => (int) (clone $query)->count(),
            'pending_total' => (float) (clone $query)->sum('amount_pending'),
            'pending_count' => (int) (clone $query)->where('amount_pending', '>', 0)->count(),
        ];
    }
}
