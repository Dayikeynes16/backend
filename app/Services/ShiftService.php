<?php

namespace App\Services;

use App\Exceptions\ShiftAlreadyOpenException;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Centraliza la apertura y cierre de turnos de caja para reusar entre el
 * controlador Inertia (Caja\TurnoController) y la API del hub.
 */
class ShiftService
{
    public function __construct(
        private ShiftTotalsCalculator $totals,
        private ShiftCashOutCalculator $cashOut,
    ) {}

    /** Turno abierto del usuario, o null. */
    public function current(User $user): ?CashRegisterShift
    {
        return CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * Abre un turno para el usuario.
     *
     * @throws ShiftAlreadyOpenException si ya hay uno abierto
     */
    public function open(User $user, float $openingAmount = 0): CashRegisterShift
    {
        if ($this->current($user) !== null) {
            throw new ShiftAlreadyOpenException;
        }

        return CashRegisterShift::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => $openingAmount,
        ]);
    }

    /**
     * Cierra el turno abierto del usuario y devuelve el shift cerrado.
     *
     * @param  array{declared_amount?: float|null, declared_card?: float|null, declared_transfer?: float|null, notes?: string|null}  $declared
     *
     * @throws ModelNotFoundException si no hay turno abierto
     */
    public function close(User $user, array $declared): CashRegisterShift
    {
        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $closingAt = now();
        $totals = $this->totals->compute($user->branch_id, $user->id, $shift->opened_at, $closingAt);

        $totalCash = $totals['total_cash'];
        $totalCard = $totals['total_card'];
        $totalTransfer = $totals['total_transfer'];
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $enabled = $this->enabledMethodsFor($user->branch_id);
        $withMovement = array_filter([
            'cash' => $totalCash > 0,
            'card' => $totalCard > 0,
            'transfer' => $totalTransfer > 0,
        ]);
        $effective = array_values(array_unique(array_merge($enabled, array_keys($withMovement))));
        if (! in_array('cash', $effective, true)) {
            $effective[] = 'cash';
        }

        $cashOutTotals = $this->cashOut->forShift($shift, $totalCash, $totalWithdrawals);
        $expectedCash = $cashOutTotals['expected_amount'];

        $declaredCash = in_array('cash', $effective, true)
            ? round((float) ($declared['declared_amount'] ?? 0), 2) : null;
        $declaredCard = in_array('card', $effective, true)
            ? round((float) ($declared['declared_card'] ?? 0), 2) : null;
        $declaredTransfer = in_array('transfer', $effective, true)
            ? round((float) ($declared['declared_transfer'] ?? 0), 2) : null;

        $diffCash = $declaredCash !== null ? round($declaredCash - $expectedCash, 2) : null;
        $diffCard = $declaredCard !== null ? round($declaredCard - $totalCard, 2) : null;
        $diffTransfer = $declaredTransfer !== null ? round($declaredTransfer - $totalTransfer, 2) : null;

        $shift->update([
            'closed_at' => $closingAt,
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
            'total_cash_provider_payments' => $cashOutTotals['cash_provider_payments'],
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $totals['collections_count'],
            'sales_generated_amount' => $totals['sales_generated_amount'],
            'sales_generated_count' => $totals['sales_generated_count'],
            'collections_from_today_amount' => $totals['collections_from_today_amount'],
            'collections_from_previous_amount' => $totals['collections_from_previous_amount'],
            'declared_amount' => $declaredCash,
            'declared_card' => $declaredCard,
            'declared_transfer' => $declaredTransfer,
            'expected_amount' => $expectedCash,
            'difference' => $diffCash ?? 0,
            'difference_card' => $diffCard ?? 0,
            'difference_transfer' => $diffTransfer ?? 0,
            'notes' => $declared['notes'] ?? null,
        ]);

        return $shift->refresh();
    }

    /**
     * Métodos de pago habilitados en la sucursal.
     *
     * @return list<string>
     */
    private function enabledMethodsFor(int $branchId): array
    {
        $branch = Branch::withoutGlobalScopes()->find($branchId);

        return $branch
            ? $branch->enabledPaymentMethods()
            : Branch::SUPPORTED_PAYMENT_METHODS;
    }
}
