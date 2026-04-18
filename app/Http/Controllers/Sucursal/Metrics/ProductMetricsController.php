<?php

namespace App\Http\Controllers\Sucursal\Metrics;

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
        $branchId = $this->resolveSucursalBranchId($request);
        $range = $this->resolveDateRange($request);
        $noMovementDays = (int) $request->query('no_movement_days', 30);
        $key = $meta->cacheKey('productos:'.$noMovementDays, $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'top_by_revenue' => $service->topByRevenue($range, $branchId, $tenantId, 10),
            'top_by_quantity' => $service->topByQuantity($range, $branchId, $tenantId, 10),
            'category_share' => $service->byCategoryShare($range, $branchId, $tenantId),
            'least_sold' => $service->leastSold($range, $branchId, $tenantId, 20),
            'no_movement' => $service->withoutMovement($range, $branchId, $tenantId, $noMovementDays)->limit(100)->get()->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'cost_price' => $p->cost_price !== null ? (float) $p->cost_price : null,
                'last_sold' => $p->last_sold,
            ]),
            'price_below_cost' => $service->priceBelowCost($branchId, $tenantId),
        ]);

        return Inertia::render('Sucursal/Metricas/Productos', [
            ...$this->commonProps($request, $range, $branchId),
            'no_movement_days' => $noMovementDays,
            'data' => $data,
        ]);
    }
}
