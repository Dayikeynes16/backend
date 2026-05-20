<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\CashRegisterShift;
use App\Models\Expense;

/**
 * Calcula las SALIDAS de efectivo de un turno (Fase 1: gastos en efectivo) y
 * el efectivo esperado del corte. Centraliza la fórmula que antes vivía
 * duplicada en CajaTurnoController, CashShiftController y RecalculateClosedShifts.
 *
 * A diferencia de ShiftTotalsCalculator (que atribuye cobros por ventana de
 * tiempo), aquí las salidas se atan al turno por FK explícita
 * (expenses.cash_register_shift_id), porque se capturan desde la caja con el
 * turno conocido.
 */
class ShiftCashOutCalculator
{
    /**
     * @return array{cash_expenses: float, expected_amount: float}
     */
    public function forShift(CashRegisterShift $shift, float $totalCash, float $totalWithdrawals): array
    {
        // SoftDeletes excluye los gastos cancelados automáticamente.
        $cashExpenses = round((float) Expense::query()
            ->where('cash_register_shift_id', $shift->id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->sum('amount'), 2);

        $expected = round(
            (float) $shift->opening_amount + $totalCash - $totalWithdrawals - $cashExpenses,
            2,
        );

        return [
            'cash_expenses' => $cashExpenses,
            'expected_amount' => $expected,
        ];
    }
}
