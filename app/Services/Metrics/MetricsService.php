<?php

namespace App\Services\Metrics;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class MetricsService
{
    public function __construct(
        protected SalesMetrics $sales,
        protected MarginMetrics $margin,
        protected ProductMetrics $products,
        protected CustomerMetrics $customers,
        protected CashierMetrics $cashiers,
        protected ShiftMetrics $shifts,
        protected CollectionMetrics $collection,
    ) {}

    public function dashboardSummary(DateRange $range, ?int $branchId, int $tenantId, bool $bypassCache = false, array $statuses = SalesMetrics::DEFAULT_STATUSES): array
    {
        $statusKey = implode('-', $statuses) ?: 'none';
        $key = $this->cacheKey("index:{$statusKey}", $range, $branchId, $tenantId);

        if ($bypassCache) {
            Cache::forget($key);
        }

        return Cache::remember($key, 300, fn () => [
            'sales' => $this->sales->summary($range, $branchId, $tenantId, $statuses),
            'margin' => $this->margin->summary($range, $branchId, $tenantId),
            'collection' => $this->collection->summary($range, $branchId, $tenantId),
            'daily_series' => $this->sales->dailySeries($range, $branchId, $tenantId, $statuses),
            'previous_daily_series' => $this->sales->dailySeries($range->previousComparable(), $branchId, $tenantId, $statuses),
            'heatmap' => $this->sales->hourDayHeatmap($range, $branchId, $tenantId, $statuses),
            'top_products_by_margin' => $this->margin->byProduct($range, $branchId, $tenantId, 5),
        ]);
    }

    public function backfillDate(): ?string
    {
        return Setting::get('metrics.backfill_run_at');
    }

    public function cacheKey(string $axis, DateRange $range, ?int $branchId, int $tenantId): string
    {
        $branchKey = $branchId ?? 'all';

        return "metrics:{$tenantId}:{$branchKey}:{$axis}:{$range->hash()}";
    }

    public function forget(string $axis, DateRange $range, ?int $branchId, int $tenantId): void
    {
        Cache::forget($this->cacheKey($axis, $range, $branchId, $tenantId));
    }
}
