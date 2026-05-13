<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de cancelaciones. Filtro base consistente con SalesMetrics::cancelled():
 * status='cancelled' AND deleted_at IS NULL AND cancelled_at BETWEEN start AND end,
 * más tenant + branch opcional. Para % sobre ventas, usa SalesMetrics como fuente
 * única (completed+pending = ventas brutas incluidas las de crédito).
 */
class CancellationMetrics extends AbstractMetrics
{
    public function __construct(protected SalesMetrics $sales) {}

    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return [
            'current' => $this->aggregate($range, $branchId, $tenantId),
            'previous' => $this->aggregate($range->previousComparable(), $branchId, $tenantId),
        ];
    }

    private function aggregate(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $row = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->selectRaw('
                COUNT(*) as cnt,
                COALESCE(SUM(total), 0) as amount,
                AVG(CASE WHEN cancel_requested_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (cancelled_at - cancel_requested_at)) / 60.0
                    END) as avg_minutes,
                SUM(CASE WHEN cancel_requested_by IS NOT NULL THEN 1 ELSE 0 END) as from_request
            ')
            ->first();

        $cnt = (int) $row->cnt;
        $amount = (float) $row->amount;
        $fromRequest = (int) $row->from_request;

        $sales = $this->sales->summary(
            $range,
            $branchId,
            $tenantId,
            [SaleStatus::Completed->value, SaleStatus::Pending->value],
        )['current'];
        $gross = (float) ($sales['gross_sales'] ?? 0);

        return [
            'cancelled_count' => $cnt,
            'cancelled_amount' => $amount,
            'gross_sales' => $gross,
            'pct_of_sales' => $gross > 0 ? round(($amount / $gross) * 100, 2) : null,
            'avg_response_minutes' => $row->avg_minutes !== null ? round((float) $row->avg_minutes, 1) : null,
            'from_request_count' => $fromRequest,
            'direct_count' => $cnt - $fromRequest,
        ];
    }

    /**
     * Serie por día con conteo y monto. Días sin datos se rellenan a 0 vía
     * zeroFillDays() para que la gráfica tenga puntos alineados con el rango.
     */
    public function daily(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $rows = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->selectRaw('DATE(cancelled_at) as day, COUNT(*) as cnt, COALESCE(SUM(total), 0) as amount')
            ->groupBy('day')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->day => [
                'count' => (int) $r->cnt,
                'amount' => (float) $r->amount,
            ]])
            ->all();

        return $this->zeroFillDays($range, $rows, ['count' => 0, 'amount' => 0.0]);
    }

    /**
     * Agrupa por motivo (NULL → "Sin motivo"). Ordenado por conteo desc.
     */
    public function byReason(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $rows = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->selectRaw("COALESCE(NULLIF(TRIM(cancel_reason), ''), 'Sin motivo') as reason,
                         COUNT(*) as cnt,
                         COALESCE(SUM(total), 0) as amount")
            ->groupBy('reason')
            ->orderByDesc('cnt')
            ->get();

        $totalCount = (int) $rows->sum('cnt');

        return $rows->map(fn ($r) => [
            'reason' => $r->reason,
            'count' => (int) $r->cnt,
            'amount' => (float) $r->amount,
            'pct_of_count' => $totalCount > 0 ? round(((int) $r->cnt / $totalCount) * 100, 2) : 0.0,
        ])->all();
    }

    /**
     * Por usuario: cuántas canceló (como cancelled_by) y cuántas solicitó
     * (como cancel_requested_by, contando solo solicitudes que terminaron en
     * cancelación dentro del rango). Quienes solo solicitan pero no cancelan
     * aparecen con cancelled_count = 0.
     */
    public function byCashier(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $cancelled = DB::table('sales as s')
            ->join('users as u', 'u.id', '=', 's.cancelled_by')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Cancelled->value)
            ->whereNull('s.deleted_at')
            ->whereBetween('s.cancelled_at', [$range->start, $range->end])
            ->selectRaw('u.id, u.name, COUNT(*) as cnt, COALESCE(SUM(s.total), 0) as amount')
            ->groupBy('u.id', 'u.name')
            ->get()
            ->keyBy('id');

        $requested = DB::table('sales as s')
            ->join('users as u', 'u.id', '=', 's.cancel_requested_by')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Cancelled->value)
            ->whereNull('s.deleted_at')
            ->whereBetween('s.cancelled_at', [$range->start, $range->end])
            ->selectRaw('u.id, u.name, COUNT(*) as cnt')
            ->groupBy('u.id', 'u.name')
            ->get()
            ->keyBy('id');

        $allIds = $cancelled->keys()->merge($requested->keys())->unique();

        return $allIds
            ->map(function ($id) use ($cancelled, $requested) {
                $c = $cancelled->get($id);
                $r = $requested->get($id);

                return [
                    'id' => (int) $id,
                    'name' => $c->name ?? $r->name ?? null,
                    'cancelled_count' => (int) ($c->cnt ?? 0),
                    'cancelled_amount' => (float) ($c->amount ?? 0),
                    'requested_count' => (int) ($r->cnt ?? 0),
                ];
            })
            ->sort(fn ($a, $b) => $b['cancelled_count'] <=> $a['cancelled_count']
                ?: $b['requested_count'] <=> $a['requested_count'])
            ->values()
            ->all();
    }

    /**
     * Por sucursal: # canceladas, monto y % sobre las ventas brutas de esa
     * sucursal en el rango. Solo lo consume la vista de empresa.
     */
    public function byBranch(DateRange $range, int $tenantId): array
    {
        $rows = DB::table('sales as s')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.tenant_id', $tenantId)
            ->where('s.status', SaleStatus::Cancelled->value)
            ->whereNull('s.deleted_at')
            ->whereBetween('s.cancelled_at', [$range->start, $range->end])
            ->selectRaw('b.id, b.name, COUNT(*) as cnt, COALESCE(SUM(s.total), 0) as amount')
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('amount')
            ->get();

        return $rows->map(function ($r) use ($range, $tenantId) {
            $branchSales = $this->sales->summary(
                $range,
                (int) $r->id,
                $tenantId,
                [SaleStatus::Completed->value, SaleStatus::Pending->value],
            )['current'];
            $gross = (float) ($branchSales['gross_sales'] ?? 0);
            $amount = (float) $r->amount;

            return [
                'branch_id' => (int) $r->id,
                'name' => $r->name,
                'cancelled_count' => (int) $r->cnt,
                'cancelled_amount' => $amount,
                'pct_of_sales' => $gross > 0 ? round(($amount / $gross) * 100, 2) : null,
            ];
        })->all();
    }
}
