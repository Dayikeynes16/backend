<?php

namespace App\Services;

use App\Enums\SaleStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calcula los totales de un turno separando claramente:
 *  - ventas generadas durante el turno (lo que el cajero realmente vendió)
 *  - cobranza recibida durante el turno, dividida entre:
 *      - pagos a ventas del propio turno (cobranza "natural" del día)
 *      - pagos a ventas anteriores al turno (abonos a cuentas viejas)
 *
 * El criterio de "venta del turno" es `sales.created_at >= shift.opened_at`.
 * Todo lo anterior se considera cuenta vieja.
 *
 * Filtros:
 *  - Cobranza: `payments.user_id = userId` (atribución por cajero, igual que
 *    el cálculo legacy del cierre)
 *  - Ventas generadas: `sales.user_id = userId` (mismo criterio)
 */
class ShiftTotalsCalculator
{
    /**
     * @return array{
     *   total_cash: float,
     *   total_card: float,
     *   total_transfer: float,
     *   collections_from_today_amount: float,
     *   collections_from_previous_amount: float,
     *   collections_count: int,
     *   sales_generated_amount: float,
     *   sales_generated_count: int,
     * }
     */
    public function compute(int $branchId, int $userId, Carbon $openedAt, ?Carbon $closedAt = null): array
    {
        $closedAt = $closedAt ?? now();

        $paymentsAggregate = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('p.user_id', $userId)
            ->where('s.branch_id', $branchId)
            ->whereBetween('p.created_at', [$openedAt, $closedAt])
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN p.method = 'cash' THEN p.amount END), 0) AS cash_amt,
                COALESCE(SUM(CASE WHEN p.method = 'card' THEN p.amount END), 0) AS card_amt,
                COALESCE(SUM(CASE WHEN p.method = 'transfer' THEN p.amount END), 0) AS transfer_amt,
                COALESCE(SUM(CASE WHEN s.created_at >= ? THEN p.amount END), 0) AS today_amt,
                COALESCE(SUM(CASE WHEN s.created_at <  ? THEN p.amount END), 0) AS previous_amt,
                COUNT(DISTINCT p.sale_id) AS sales_paid_count
            ", [$openedAt, $openedAt])
            ->first();

        $generatedSales = DB::table('sales')
            ->where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$openedAt, $closedAt])
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS amt')
            ->first();

        return [
            'total_cash' => round((float) $paymentsAggregate->cash_amt, 2),
            'total_card' => round((float) $paymentsAggregate->card_amt, 2),
            'total_transfer' => round((float) $paymentsAggregate->transfer_amt, 2),
            'collections_from_today_amount' => round((float) $paymentsAggregate->today_amt, 2),
            'collections_from_previous_amount' => round((float) $paymentsAggregate->previous_amt, 2),
            'collections_count' => (int) $paymentsAggregate->sales_paid_count,
            'sales_generated_amount' => round((float) $generatedSales->amt, 2),
            'sales_generated_count' => (int) $generatedSales->cnt,
        ];
    }
}
