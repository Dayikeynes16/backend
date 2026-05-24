<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compras pagadas en efectivo desde la caja, ligadas al turno abierto.
 * Reusa la creación de compra del trait y PurchasePaymentService para el pago.
 * El módulo se habilita/deshabilita por sucursal desde el panel de empresa.
 */
class PurchaseController extends Controller
{
    use HandlesPurchases;

    /**
     * Listado de las compras que el cajero ha registrado (solo las suyas),
     * sin filtros de fecha. Registrar exige turno abierto.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $this->ensureModuleEnabled($user->branch_id);

        $query = Purchase::query()
            ->where('branch_id', $user->branch_id)
            ->where('created_by', $user->id)
            ->with(['provider:id,name', 'items', 'payments'])
            ->when($request->search, function ($q, $s) {
                $q->where(fn ($q2) => $q2
                    ->where('folio', 'ilike', "%{$s}%")
                    ->orWhere('invoice_number', 'ilike', "%{$s}%")
                    ->orWhereHas('provider', fn ($pq) => $pq->where('name', 'ilike', "%{$s}%")));
            });

        $purchases = (clone $query)
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        return Inertia::render('Caja/Compras/Index', [
            'purchases' => $purchases,
            'totals' => [
                'amount' => (float) (clone $query)->sum('total'),
                'count' => (clone $query)->count(),
            ],
            'providers' => Provider::where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']),
            'purchaseProducts' => PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
            'hasOpenShift' => $hasOpenShift,
            'filters' => $request->only('search'),
            'tenant' => app('tenant'),
        ]);
    }

    /**
     * Aborta si la sucursal del cajero tiene deshabilitado el módulo de compras.
     */
    private function ensureModuleEnabled(?int $branchId): void
    {
        $branch = $branchId ? Branch::find($branchId) : null;

        if (! $branch || ! $branch->cashier_purchases_enabled) {
            abort(403, 'El registro de compras no está habilitado para tu sucursal.');
        }
    }

    public function store(Request $request, PurchaseFolioGenerator $folios, PurchasePaymentService $payments): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $this->ensureModuleEnabled($user->branch_id);

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            abort(422, 'Abre tu turno antes de registrar una compra.');
        }

        // El branch lo fija el turno (no se confía en el form); se inyecta para
        // que la validación compartida (que exige branch_id) pase.
        $request->merge(['branch_id' => $shift->branch_id]);

        $validated = $this->validatedPurchasePayload($request);
        $paid = round((float) $request->input('paid_amount', 0), 2);
        if ($paid < 0) {
            $paid = 0;
        }

        $purchase = $this->createPurchaseWithItems(
            $validated,
            $shift->branch_id,
            $tenant,
            $folios,
            ['cash_register_shift_id' => $shift->id],
        );

        if ($paid > 0) {
            // applyPayment valida que no exceda el total y recalcula.
            $payments->applyPayment($purchase, [
                'amount' => $paid,
                'payment_method' => 'cash',
                'user_id' => $user->id,
                'cash_register_shift_id' => $shift->id,
            ]);
        } else {
            $payments->recalculate($purchase);
        }

        return back()->with('success', 'Compra en efectivo registrada.');
    }

    // ─── Hooks del trait (sólo se usa store) ─────────────────────────────

    protected function resolveBranchIdForWrite(Request $request): int
    {
        return (int) Auth::user()->branch_id;
    }

    protected function applyBranchScopeToQuery(Builder $query): Builder
    {
        return $query->where('branch_id', (int) Auth::user()->branch_id);
    }

    protected function assertCanMutate(Purchase $purchase): void
    {
        if ($purchase->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    protected function redirectAfterWrite(Request $request, string $message): RedirectResponse
    {
        return back()->with('success', $message);
    }
}
