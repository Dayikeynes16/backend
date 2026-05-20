<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Purchase;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Captura de compra pagada en efectivo desde la caja, ligada al turno abierto.
 * Reusa la creación de compra del trait y PurchasePaymentService para el pago.
 */
class PurchaseController extends Controller
{
    use HandlesPurchases;

    public function store(Request $request, PurchaseFolioGenerator $folios, PurchasePaymentService $payments): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

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
