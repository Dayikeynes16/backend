<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de Compras para admin-sucursal. Forzosamente scopeado a su branch_id.
 */
class PurchaseController extends Controller
{
    use HandlesPurchases;

    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $query = Purchase::query()->with([
            'provider:id,name', 'branch:id,name',
            'items', 'attachments', 'payments',
        ]);
        $query = $this->applyBranchScopeToQuery($query);
        $query = $this->applyIndexFilters($query, $request);

        $purchases = $query
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Purchase $p) => $this->serializePurchase($p));

        return Inertia::render('Sucursal/Compras/Index', [
            'purchases' => $purchases,
            'filters' => [
                'q' => $request->input('q'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'provider_id' => $request->integer('provider_id'),
                'status' => $request->input('status', 'all'),
                'payment_status' => $request->input('payment_status'),
            ],
            'providers' => Provider::where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']),
            'purchaseProducts' => PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
            'kpis' => $this->kpis(),
            'branch' => ['id' => $user->branch_id, 'name' => optional($user->branch)->name],
        ]);
    }

    // ─── Hooks del trait ─────────────────────────────────────────────────

    protected function resolveBranchIdForWrite(Request $request): int
    {
        // admin-sucursal: NUNCA confiamos en lo que mandó el form.
        $branchId = (int) Auth::user()->branch_id;
        if (! $branchId) {
            abort(403, 'Tu usuario no tiene sucursal asignada.');
        }

        return $branchId;
    }

    protected function applyBranchScopeToQuery(Builder $query): Builder
    {
        $branchId = (int) Auth::user()->branch_id;

        return $query->where('branch_id', $branchId);
    }

    protected function assertCanMutate(Purchase $purchase): void
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if ($purchase->tenant_id !== $tenant->id) {
            abort(404);
        }
        if ($purchase->branch_id !== $user->branch_id) {
            abort(403, 'Esta compra pertenece a otra sucursal.');
        }
    }

    protected function redirectAfterWrite(Request $request, string $message): RedirectResponse
    {
        return redirect()
            ->route('sucursal.compras.index', app('tenant')->slug)
            ->with('success', $message);
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
    private function kpis(): array
    {
        $branchId = (int) Auth::user()->branch_id;
        $base = Purchase::query()
            ->where('branch_id', $branchId)
            ->where('status', '!=', PurchaseStatus::Cancelled);

        return [
            'total_amount' => (float) (clone $base)->sum('total'),
            'count' => (int) (clone $base)->count(),
            'pending_total' => (float) (clone $base)->sum('amount_pending'),
            'pending_count' => (int) (clone $base)->where('amount_pending', '>', 0)->count(),
        ];
    }
}
