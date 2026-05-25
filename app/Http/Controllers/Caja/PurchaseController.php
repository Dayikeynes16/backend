<?php

namespace App\Http\Controllers\Caja;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\ProviderPayment;
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

        $shift = $this->openShift();
        $shiftId = $shift?->id;

        $query = Purchase::query()
            ->where('branch_id', $user->branch_id)
            ->where('created_by', $user->id)
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->with(['provider:id,name', 'branch:id,name', 'items', 'payments', 'attachments', 'history.user:id,name'])
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
            ->withQueryString()
            ->through(function (Purchase $p) use ($shiftId) {
                $data = $this->serializePurchase($p);
                $data['can_manage'] = $shiftId !== null
                    && $p->cash_register_shift_id === $shiftId
                    && $p->status !== PurchaseStatus::Cancelled;

                return $data;
            });

        $hasOpenShift = $shift !== null;

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

    /**
     * Turno abierto del cajero actual (o null).
     */
    private function openShift(): ?CashRegisterShift
    {
        return CashRegisterShift::where('user_id', Auth::id())
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * Candado de Caja: la compra debe ser del cajero y pertenecer a su turno
     * abierto. Si no, 403.
     */
    private function assertCajaCanMutate(Purchase $purchase): CashRegisterShift
    {
        if ($purchase->tenant_id !== app('tenant')->id) {
            abort(404);
        }
        $shift = $this->openShift();
        if (! $shift
            || $purchase->created_by !== Auth::id()
            || $purchase->cash_register_shift_id !== $shift->id) {
            abort(403, 'Solo puedes corregir tus compras del turno abierto.');
        }

        return $shift;
    }

    public function update(Request $request, Purchase $compra, PurchasePaymentService $payments): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($compra);

        // Reusa la lógica compartida (incluye regla "total >= pagado" e historial).
        return $this->updatePurchase($request, $compra, $payments);
    }

    public function cancel(Request $request, Purchase $compra): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($compra);

        return $this->cancelPurchase($request, $compra);
    }

    public function storePayment(Request $request, Purchase $compra, PurchasePaymentService $payments): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $shift = $this->assertCajaCanMutate($compra);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $payments->applyPayment($compra, [
            'amount' => $validated['amount'],
            'payment_method' => 'cash',
            'user_id' => Auth::id(),
            'cash_register_shift_id' => $shift->id,
        ]);

        return back()->with('success', 'Pago registrado.');
    }

    public function destroyPayment(Request $request, Purchase $compra, ProviderPayment $pago, PurchasePaymentService $payments): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($compra);

        if ($pago->purchase_id !== $compra->id) {
            abort(404);
        }
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $payments->cancelPayment($pago, Auth::id(), $reason);

        return back()->with('success', 'Pago cancelado.');
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
