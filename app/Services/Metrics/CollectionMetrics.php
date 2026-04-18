<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class CollectionMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $row = DB::table('customer_payments')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('cancelled_at')
            ->whereBetween('created_at', [$range->start, $range->end])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount_applied), 0) as t')
            ->first();

        $totalPending = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('customer_id')
            ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::Pending->value, SaleStatus::Active->value])
            ->sum('amount_pending');

        $avgDays = $this->averageDaysToCollect($range, $branchId, $tenantId);

        return [
            'total_collected' => (float) $row->t,
            'payment_count' => (int) $row->c,
            'total_pending_balance' => (float) $totalPending,
            'avg_days_to_collect' => $avgDays,
        ];
    }

    public function dailyCollection(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return DB::table('customer_payments')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('cancelled_at')
            ->whereBetween('created_at', [$range->start, $range->end])
            ->selectRaw('DATE(created_at) as day, SUM(amount_applied) as amount')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'amount' => (float) $r->amount])
            ->all();
    }

    public function aging(?int $branchId, int $tenantId): array
    {
        $now = now();
        $buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-plus' => 0.0];

        $rows = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('customer_id')
            ->where('amount_pending', '>', 0)
            ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::Pending->value, SaleStatus::Active->value])
            ->select('amount_pending', 'completed_at', 'created_at')
            ->get();

        foreach ($rows as $r) {
            $dateRef = $r->completed_at ?? $r->created_at;
            $days = $now->diffInDays($dateRef);
            $amt = (float) $r->amount_pending;
            if ($days <= 30) {
                $buckets['0-30'] += $amt;
            } elseif ($days <= 60) {
                $buckets['31-60'] += $amt;
            } else {
                $buckets['61-plus'] += $amt;
            }
        }

        return $buckets;
    }

    public function receivablesTable(?int $branchId, int $tenantId, int $limit = 200): array
    {
        return DB::table('customers as c')
            ->join('sales as s', 's.customer_id', '=', 'c.id')
            ->where('c.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('c.branch_id', $branchId))
            ->where('s.amount_pending', '>', 0)
            ->whereIn('s.status', [SaleStatus::Completed->value, SaleStatus::Pending->value, SaleStatus::Active->value])
            ->leftJoin(DB::raw('(SELECT customer_id, MAX(created_at) as last_pay FROM customer_payments WHERE cancelled_at IS NULL GROUP BY customer_id) cp'), 'cp.customer_id', '=', 'c.id')
            ->selectRaw('
                c.id, c.name, c.phone,
                SUM(s.amount_pending) as balance,
                COUNT(s.id) as pending_sales,
                MAX(s.completed_at) as last_sale,
                MAX(cp.last_pay) as last_payment,
                MIN(s.completed_at) as oldest_sale
            ')
            ->groupBy('c.id', 'c.name', 'c.phone')
            ->orderByDesc('balance')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $oldest = $r->oldest_sale ?: $r->last_sale;
                $days = $oldest ? now()->diffInDays($oldest) : 0;

                return [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'phone' => $r->phone,
                    'balance' => (float) $r->balance,
                    'pending_sales' => (int) $r->pending_sales,
                    'last_sale' => $r->last_sale,
                    'last_payment' => $r->last_payment,
                    'age_days' => (int) $days,
                ];
            })
            ->all();
    }

    private function averageDaysToCollect(DateRange $range, ?int $branchId, int $tenantId): ?float
    {
        $row = DB::table('customer_payments as cp')
            ->join('sales as s', function ($j) {
                $j->on('s.customer_id', '=', 'cp.customer_id')
                    ->on('s.amount_paid', '>', DB::raw('0'));
            })
            ->where('cp.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('cp.branch_id', $branchId))
            ->whereNull('cp.cancelled_at')
            ->whereBetween('cp.created_at', [$range->start, $range->end])
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (cp.created_at - s.completed_at)) / 86400) as avg_days')
            ->first();

        return $row->avg_days !== null ? round((float) $row->avg_days, 1) : null;
    }
}
