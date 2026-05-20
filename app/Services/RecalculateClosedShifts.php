<?php

namespace App\Services;

use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;

/**
 * Recalcula los totales de turnos cerrados que incluyeron pagos de una venta
 * que cambió (cancelación, edición de pagos, abonos globales eliminados).
 *
 * Reutiliza ShiftTotalsCalculator para mantener consistencia con el cálculo
 * del cierre original. Reemplaza tres copias casi idénticas de esta lógica
 * (Workbench, CancelRequest, CustomerPayment).
 */
class RecalculateClosedShifts
{
    public function __construct(
        private ShiftTotalsCalculator $calculator,
        private ShiftCashOutCalculator $cashOut,
    ) {}

    /**
     * Recalcula un turno cerrado puntual (p. ej. cuando se cancela/edita un
     * gasto en efectivo ligado a él).
     */
    public function forShift(CashRegisterShift $shift): void
    {
        if (! $shift->closed_at) {
            return;
        }
        $this->recompute($shift);
    }

    public function forSale(Sale $sale): void
    {
        $payments = Payment::withTrashed()
            ->where('sale_id', $sale->id)
            ->get();

        $affectedUserIds = $payments->pluck('user_id')->unique();

        foreach ($affectedUserIds as $userId) {
            $userPayments = $payments->where('user_id', $userId);
            $earliest = $userPayments->min('created_at');
            if (! $earliest) {
                continue;
            }

            $shifts = CashRegisterShift::where('user_id', $userId)
                ->whereNotNull('closed_at')
                ->where('opened_at', '<=', $earliest)
                ->get();

            foreach ($shifts as $shift) {
                $this->recompute($shift);
            }
        }
    }

    private function recompute(CashRegisterShift $shift): void
    {
        $totals = $this->calculator->compute(
            $shift->branch_id,
            $shift->user_id,
            $shift->opened_at,
            $shift->closed_at,
        );

        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
        $cashOutTotals = $this->cashOut->forShift($shift, $totals['total_cash'], $totalWithdrawals);
        $expected = $cashOutTotals['expected_amount'];
        $declared = (float) $shift->declared_amount;

        $shift->update([
            'total_cash' => $totals['total_cash'],
            'total_card' => $totals['total_card'],
            'total_transfer' => $totals['total_transfer'],
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
            'total_cash_provider_payments' => $cashOutTotals['cash_provider_payments'],
            'total_sales' => $totals['total_cash'] + $totals['total_card'] + $totals['total_transfer'],
            'sale_count' => $totals['collections_count'],
            'sales_generated_amount' => $totals['sales_generated_amount'],
            'sales_generated_count' => $totals['sales_generated_count'],
            'collections_from_today_amount' => $totals['collections_from_today_amount'],
            'collections_from_previous_amount' => $totals['collections_from_previous_amount'],
            'expected_amount' => $expected,
            'difference' => round($declared - $expected, 2),
        ]);
    }
}
