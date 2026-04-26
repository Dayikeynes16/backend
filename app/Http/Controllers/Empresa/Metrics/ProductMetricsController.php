<?php

namespace App\Http\Controllers\Empresa\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\ProductMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ProductMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, ProductMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);
        $noMovementDays = (int) $request->query('no_movement_days', 30);
        $statuses = $this->resolveStatuses($request);

        $statusKey = implode('-', $statuses);
        $key = $meta->cacheKey("productos:{$noMovementDays}:{$statusKey}", $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId, $statuses),
            'all_products' => $service->byProductFull($range, $branchId, $tenantId, $statuses),
            'top_by_revenue' => $service->topByRevenue($range, $branchId, $tenantId, 10, $statuses),
            'top_by_profit' => $service->topByProfit($range, $branchId, $tenantId, 10, $statuses),
            'category_share' => $service->byCategoryShare($range, $branchId, $tenantId, $statuses),
            'no_movement' => $service->withoutMovement($range, $branchId, $tenantId, $noMovementDays)->limit(100)->get()->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'cost_price' => $p->cost_price !== null ? (float) $p->cost_price : null,
                'last_sold' => $p->last_sold,
            ]),
            'price_below_cost' => $service->priceBelowCost($branchId, $tenantId),
        ]);

        return Inertia::render('Empresa/Metricas/Productos', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'no_movement_days' => $noMovementDays,
            'statuses' => $statuses,
            'data' => $data,
        ]);
    }
}
