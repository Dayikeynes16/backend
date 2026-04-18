<?php

namespace App\Http\Controllers\Empresa\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\CollectionMetrics;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class CollectionMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, CollectionMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);
        $key = $meta->cacheKey('cobranza', $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'daily_collection' => $service->dailyCollection($range, $branchId, $tenantId),
            'aging' => $service->aging($branchId, $tenantId),
            'receivables' => $service->receivablesTable($branchId, $tenantId, 200),
        ]);

        return Inertia::render('Empresa/Metricas/Cobranza', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'data' => $data,
        ]);
    }
}
