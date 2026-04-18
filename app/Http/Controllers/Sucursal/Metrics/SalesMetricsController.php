<?php

namespace App\Http\Controllers\Sucursal\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\SalesMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SalesMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, SalesMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveSucursalBranchId($request);
        $range = $this->resolveDateRange($request);
        $key = $meta->cacheKey('ventas', $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'daily_series' => $service->dailySeries($range, $branchId, $tenantId),
            'previous_daily_series' => $service->dailySeries($range->previousComparable(), $branchId, $tenantId),
            'heatmap' => $service->hourDayHeatmap($range, $branchId, $tenantId),
            'daily_table' => $service->dailyTable($range, $branchId, $tenantId),
        ]);

        return Inertia::render('Sucursal/Metricas/Ventas', [
            ...$this->commonProps($request, $range, $branchId),
            'data' => $data,
        ]);
    }
}
