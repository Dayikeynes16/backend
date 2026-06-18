<?php

namespace App\Http\Controllers\Empresa\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\OverviewMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class MetricsIndexController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, OverviewMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);

        $withBranchComparison = $branchId === null;
        $key = $meta->cacheKey('resumen:'.($withBranchComparison ? 'all' : 'one'), $range, $branchId, $tenantId);

        $data = Cache::remember($key, 300, fn () => $service->build($range, $branchId, $tenantId, $withBranchComparison));

        return Inertia::render('Empresa/Metricas/Index', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'data' => $data,
        ]);
    }
}
