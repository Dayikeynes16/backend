<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class SalesMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return [
            'current' => $this->aggregateFor($range, $branchId, $tenantId),
            'previous' => $this->aggregateFor($range->previousComparable(), $branchId, $tenantId),
        ];
    }

    public function aggregateFor(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $completedQuery = fn () => DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Completed->value)
            ->where('amount_pending', '<=', 0)
            ->whereNull('deleted_at')
            ->whereBetween('completed_at', [$range->start, $range->end]);

        $row = $completedQuery()
            ->selectRaw('COUNT(*) as ticket_count, COALESCE(SUM(total), 0) as total_sales, COALESCE(AVG(total), 0) as avg_ticket')
            ->first();

        $byMethod = $completedQuery()
            ->selectRaw('payment_method, COALESCE(SUM(total), 0) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->toArray();

        $cancelled = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total), 0) as t')
            ->first();

        return [
            'total_sales' => (float) $row->total_sales,
            'ticket_count' => (int) $row->ticket_count,
            'avg_ticket' => round((float) $row->avg_ticket, 2),
            'cancelled_count' => (int) $cancelled->c,
            'cancelled_amount' => (float) $cancelled->t,
            'by_method' => [
                'cash' => (float) ($byMethod['cash'] ?? 0),
                'card' => (float) ($byMethod['card'] ?? 0),
                'transfer' => (float) ($byMethod['transfer'] ?? 0),
                'credit' => (float) ($byMethod['credit'] ?? 0),
            ],
        ];
    }

    public function dailySeries(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Completed->value)
            ->where('amount_pending', '<=', 0)
            ->whereNull('deleted_at')
            ->whereBetween('completed_at', [$range->start, $range->end])
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as tickets, SUM(total) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'tickets' => (int) $r->tickets,
                'total' => (float) $r->total,
            ])
            ->all();
    }

    public function hourDayHeatmap(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $rows = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Completed->value)
            ->where('amount_pending', '<=', 0)
            ->whereNull('deleted_at')
            ->whereBetween('completed_at', [$range->start, $range->end])
            ->selectRaw('EXTRACT(ISODOW FROM completed_at) as dow, EXTRACT(HOUR FROM completed_at) as hour, COUNT(*) as tickets, SUM(total) as total')
            ->groupBy('dow', 'hour')
            ->get();

        $matrix = [];
        for ($d = 1; $d <= 7; $d++) {
            for ($h = 0; $h <= 23; $h++) {
                $matrix[$d][$h] = ['tickets' => 0, 'total' => 0.0];
            }
        }

        foreach ($rows as $r) {
            $matrix[(int) $r->dow][(int) $r->hour] = [
                'tickets' => (int) $r->tickets,
                'total' => (float) $r->total,
            ];
        }

        return $matrix;
    }

    public function dailyTable(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('deleted_at')
            ->whereBetween('completed_at', [$range->start, $range->end])
            ->selectRaw('
                DATE(completed_at) as day,
                SUM(CASE WHEN status = ? AND amount_pending <= 0 THEN 1 ELSE 0 END) as tickets,
                SUM(CASE WHEN status = ? AND amount_pending <= 0 THEN total ELSE 0 END) as total,
                AVG(CASE WHEN status = ? AND amount_pending <= 0 THEN total END) as avg_ticket,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled
            ', [
                SaleStatus::Completed->value,
                SaleStatus::Completed->value,
                SaleStatus::Completed->value,
                SaleStatus::Cancelled->value,
            ])
            ->groupBy('day')
            ->orderByDesc('day')
            ->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'tickets' => (int) $r->tickets,
                'total' => (float) $r->total,
                'avg_ticket' => round((float) ($r->avg_ticket ?? 0), 2),
                'cancelled' => (int) $r->cancelled,
            ])
            ->all();
    }
}
