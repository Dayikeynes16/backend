<?php

namespace App\Http\Controllers\Sucursal\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\CustomerMetrics;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class CustomerMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, CustomerMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveSucursalBranchId($request);
        $range = $this->resolveDateRange($request);
        $inactiveDays = (int) $request->query('inactive_days', 30);
        $statuses = $this->resolveStatuses($request);
        $statusKey = implode('-', $statuses) ?: 'none';
        $key = $meta->cacheKey("clientes:{$inactiveDays}:{$statusKey}", $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId, $statuses),
            'top_customers' => $service->topCustomers($range, $branchId, $tenantId, 10, $statuses),
            'with_balance' => $service->withBalance($branchId, $tenantId, 200),
            'new_customers' => $service->newCustomers($range, $branchId, $tenantId, 200),
            'inactive' => $service->inactive($branchId, $tenantId, $inactiveDays, 200),
            'aging' => $service->aging($branchId, $tenantId),
        ]);

        return Inertia::render('Sucursal/Metricas/Clientes', [
            ...$this->commonProps($request, $range, $branchId),
            'inactive_days' => $inactiveDays,
            'data' => $data,
        ]);
    }
}
