<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class CustomerMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $buyingCount = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Completed->value)
            ->where('amount_pending', '<=', 0)
            ->whereNotNull('customer_id')
            ->whereBetween('completed_at', [$range->start, $range->end])
            ->distinct('customer_id')
            ->count('customer_id');

        $newCount = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$range->start, $range->end])
            ->count();

        $withBalance = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('customer_id')
            ->where('amount_pending', '>', 0)
            ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::Pending->value, SaleStatus::Active->value])
            ->selectRaw('COUNT(DISTINCT customer_id) as cnt, COALESCE(SUM(amount_pending), 0) as total_pending')
            ->first();

        $avgTicket = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Completed->value)
            ->where('amount_pending', '<=', 0)
            ->whereNotNull('customer_id')
            ->whereBetween('completed_at', [$range->start, $range->end])
            ->avg('total');

        return [
            'buying_customers' => (int) $buyingCount,
            'new_customers' => (int) $newCount,
            'customers_with_balance' => (int) $withBalance->cnt,
            'total_pending_balance' => (float) $withBalance->total_pending,
            'avg_ticket_per_customer' => round((float) ($avgTicket ?? 0), 2),
        ];
    }

    public function topCustomers(DateRange $range, ?int $branchId, int $tenantId, int $limit = 10): array
    {
        return DB::table('sales as s')
            ->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->selectRaw('c.id, c.name, COUNT(s.id) as tickets, SUM(s.total) as total')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'tickets' => (int) $r->tickets,
                'total' => (float) $r->total,
            ])
            ->all();
    }

    public function withBalance(?int $branchId, int $tenantId, int $limit = 200): array
    {
        return DB::table('customers as c')
            ->join('sales as s', 's.customer_id', '=', 'c.id')
            ->where('c.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('c.branch_id', $branchId))
            ->where('s.amount_pending', '>', 0)
            ->whereIn('s.status', [SaleStatus::Completed->value, SaleStatus::Pending->value, SaleStatus::Active->value])
            ->selectRaw('
                c.id, c.name, c.phone,
                SUM(s.amount_pending) as balance,
                COUNT(s.id) as pending_sales,
                MAX(s.completed_at) as last_sale,
                MIN(s.completed_at) as oldest_sale
            ')
            ->groupBy('c.id', 'c.name', 'c.phone')
            ->orderByDesc('balance')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'phone' => $r->phone,
                'balance' => (float) $r->balance,
                'pending_sales' => (int) $r->pending_sales,
                'last_sale' => $r->last_sale,
                'oldest_sale' => $r->oldest_sale,
            ])
            ->all();
    }

    public function inactive(?int $branchId, int $tenantId, int $days = 30, int $limit = 200): array
    {
        $cutoff = now()->subDays($days);

        return DB::table('customers as c')
            ->leftJoin(DB::raw('(SELECT customer_id, MAX(completed_at) as last_sale
                FROM sales WHERE status = \''.SaleStatus::Completed->value.'\' AND amount_pending <= 0 AND customer_id IS NOT NULL
                GROUP BY customer_id) ls'), 'ls.customer_id', '=', 'c.id')
            ->where('c.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('c.branch_id', $branchId))
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('ls.last_sale')->orWhere('ls.last_sale', '<', $cutoff);
            })
            ->select('c.id', 'c.name', 'c.phone', 'ls.last_sale')
            ->orderByRaw('ls.last_sale DESC NULLS LAST')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'phone' => $r->phone,
                'last_sale' => $r->last_sale,
            ])
            ->all();
    }

    public function aging(?int $branchId, int $tenantId): array
    {
        $row = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('customer_id')
            ->whereNull('deleted_at')
            ->where('amount_pending', '>', 0)
            ->whereIn('status', [
                SaleStatus::Completed->value,
                SaleStatus::Pending->value,
                SaleStatus::Active->value,
            ])
            ->selectRaw("
                COALESCE(SUM(CASE
                    WHEN (CURRENT_DATE - COALESCE(completed_at, created_at)::date) <= 30 THEN amount_pending
                    ELSE 0
                END), 0) AS bucket_0_30,
                COALESCE(SUM(CASE
                    WHEN (CURRENT_DATE - COALESCE(completed_at, created_at)::date) BETWEEN 31 AND 60 THEN amount_pending
                    ELSE 0
                END), 0) AS bucket_31_60,
                COALESCE(SUM(CASE
                    WHEN (CURRENT_DATE - COALESCE(completed_at, created_at)::date) > 60 THEN amount_pending
                    ELSE 0
                END), 0) AS bucket_61_plus
            ")
            ->first();

        return [
            '0-30' => (float) $row->bucket_0_30,
            '31-60' => (float) $row->bucket_31_60,
            '61-plus' => (float) $row->bucket_61_plus,
        ];
    }

    public function newCustomers(DateRange $range, ?int $branchId, int $tenantId, int $limit = 200): array
    {
        return DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$range->start, $range->end])
            ->select('id', 'name', 'phone', 'created_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'phone' => $r->phone,
                'created_at' => $r->created_at,
            ])
            ->all();
    }
}
