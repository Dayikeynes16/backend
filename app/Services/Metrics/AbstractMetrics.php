<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Database\Query\Builder;

abstract class AbstractMetrics
{
    protected function scope(Builder $q, int $tenantId, ?int $branchId, string $table = ''): Builder
    {
        $prefix = $table ? "{$table}." : '';
        $q->where("{$prefix}tenant_id", $tenantId);
        if ($branchId !== null) {
            $q->where("{$prefix}branch_id", $branchId);
        }

        return $q;
    }

    /**
     * Apply the "fully paid completed sale" filter used across metric services.
     *
     * Completed status alone is not enough: credit sales stay in Completed with
     * amount_pending > 0 until collected. Revenue-like metrics must exclude those.
     */
    protected function fullyPaid(Builder $q, string $table = ''): Builder
    {
        $prefix = $table ? "{$table}." : '';
        $q->where("{$prefix}status", SaleStatus::Completed->value)
            ->where("{$prefix}amount_pending", '<=', 0);

        return $q;
    }

    protected function pct(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    protected function deltaPair(float|int $current, float|int $previous): array
    {
        return [
            'current' => (float) $current,
            'previous' => (float) $previous,
            'delta_pct' => $this->pct((float) $current, (float) $previous),
        ];
    }

    /**
     * Devuelve un punto por cada día del rango. Días sin fila reciben los
     * valores de $default. Garantiza que las series temporales para charts
     * tengan la misma cantidad de puntos que días en el rango y que la
     * comparación con el periodo previo se alinee dia por dia.
     *
     * @param  array<string, array<string, mixed>>  $rowsByDay  Indexado por 'Y-m-d'.
     * @param  array<string, mixed>  $default  Valores para días sin datos.
     * @return list<array<string, mixed>>
     */
    protected function zeroFillDays(DateRange $range, array $rowsByDay, array $default): array
    {
        $series = [];
        $cursor = $range->start->startOfDay();
        $end = $range->end->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $day = $cursor->format('Y-m-d');
            $series[] = array_merge(['day' => $day], $default, $rowsByDay[$day] ?? []);
            $cursor = $cursor->addDay();
        }

        return $series;
    }
}
