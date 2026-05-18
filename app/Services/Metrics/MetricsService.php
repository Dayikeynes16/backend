<?php

namespace App\Services\Metrics;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class MetricsService
{
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
