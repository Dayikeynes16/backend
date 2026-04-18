<?php

namespace App\Http\Controllers\Empresa\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\CashierMetrics;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class CashierMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, CashierMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);
        $key = $meta->cacheKey('cajeros', $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'by_cashier' => $service->byCashier($range, $branchId, $tenantId),
        ]);

        return Inertia::render('Empresa/Metricas/Cajeros', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'data' => $data,
        ]);
    }
}
