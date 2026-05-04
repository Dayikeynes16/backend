<?php

namespace App\Http\Controllers\Empresa\Metrics;

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
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);
        $statuses = $this->resolveStatuses($request);
        $statusKey = implode('-', $statuses) ?: 'none';
        $key = $meta->cacheKey("ventas:{$statusKey}", $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId, $statuses),
            'daily_series' => $service->dailySeries($range, $branchId, $tenantId, $statuses),
            'previous_daily_series' => $service->dailySeries($range->previousComparable(), $branchId, $tenantId, $statuses),
            'heatmap' => $service->hourDayHeatmap($range, $branchId, $tenantId, $statuses),
            'daily_table' => $service->dailyTable($range, $branchId, $tenantId, $statuses),
            'by_payment_method' => $service->byPaymentMethod($range, $branchId, $tenantId),
        ]);

        return Inertia::render('Empresa/Metricas/Ventas', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'data' => $data,
        ]);
    }
}
