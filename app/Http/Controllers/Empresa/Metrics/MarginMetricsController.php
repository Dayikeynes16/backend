<?php

namespace App\Http\Controllers\Empresa\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\MarginMetrics;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class MarginMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, MarginMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);
        $key = $meta->cacheKey('margen', $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'daily_gross_profit' => $service->dailyGrossProfit($range, $branchId, $tenantId),
            'previous_daily_gross_profit' => $service->dailyGrossProfit($range->previousComparable(), $branchId, $tenantId),
            'by_category' => $service->byCategory($range, $branchId, $tenantId),
            'by_product' => $service->byProduct($range, $branchId, $tenantId, 100),
        ]);

        return Inertia::render('Empresa/Metricas/Margen', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'data' => $data,
            'backfill_run_at' => $meta->backfillDate(),
        ]);
    }
}
