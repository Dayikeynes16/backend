<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class CashierMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $active = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('completed_at', [$range->start, $range->end])
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $top = DB::table('sales as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->selectRaw('u.id, u.name, SUM(s.total) as total')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('total')
            ->first();

        $cancelRatio = $this->avgCancelRatio($range, $branchId, $tenantId);
        $discountsTotal = $this->totalDiscounts($range, $branchId, $tenantId);

        return [
            'active_cashiers' => (int) $active,
            'top_cashier' => $top ? ['id' => (int) $top->id, 'name' => $top->name, 'total' => (float) $top->total] : null,
            'avg_cancel_ratio_pct' => $cancelRatio,
            'total_discounts' => $discountsTotal,
        ];
    }

    public function byCashier(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $rows = DB::table('sales as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->selectRaw('
                u.id, u.name,
                SUM(CASE WHEN s.status = ? THEN 1 ELSE 0 END) as tickets,
                SUM(CASE WHEN s.status = ? THEN s.total ELSE 0 END) as total,
                AVG(CASE WHEN s.status = ? THEN s.total END) as avg_ticket,
                SUM(CASE WHEN s.status = ? THEN 1 ELSE 0 END) as cancelled
            ', [
                SaleStatus::Completed->value,
                SaleStatus::Completed->value,
                SaleStatus::Completed->value,
                SaleStatus::Cancelled->value,
            ])
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('total')
            ->get();

        $discounts = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->whereNotNull('si.original_unit_price')
            ->whereColumn('si.original_unit_price', '>', 'si.unit_price')
            ->selectRaw('s.user_id, SUM((si.original_unit_price - si.unit_price) * si.quantity) as discount')
            ->groupBy('s.user_id')
            ->pluck('discount', 'user_id')
            ->toArray();

        return $rows->map(function ($r) use ($discounts) {
            $tickets = (int) $r->tickets;
            $cancelled = (int) $r->cancelled;
            $totalEvents = $tickets + $cancelled;

            return [
                'id' => (int) $r->id,
                'name' => $r->name,
                'tickets' => $tickets,
                'total' => (float) $r->total,
                'avg_ticket' => round((float) ($r->avg_ticket ?? 0), 2),
                'cancelled' => $cancelled,
                'cancel_pct' => $totalEvents > 0 ? round(($cancelled / $totalEvents) * 100, 2) : 0.0,
                'discount_total' => (float) ($discounts[$r->id] ?? 0),
            ];
        })->all();
    }

    private function avgCancelRatio(DateRange $range, ?int $branchId, int $tenantId): float
    {
        $data = $this->byCashier($range, $branchId, $tenantId);
        if (empty($data)) {
            return 0.0;
        }
        $avg = array_sum(array_column($data, 'cancel_pct')) / count($data);

        return round($avg, 2);
    }

    private function totalDiscounts(DateRange $range, ?int $branchId, int $tenantId): float
    {
        $total = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->whereNotNull('si.original_unit_price')
            ->whereColumn('si.original_unit_price', '>', 'si.unit_price')
            ->selectRaw('COALESCE(SUM((si.original_unit_price - si.unit_price) * si.quantity), 0) as d')
            ->value('d');

        return (float) $total;
    }
}
