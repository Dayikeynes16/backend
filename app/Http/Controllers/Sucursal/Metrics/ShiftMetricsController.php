<?php

namespace App\Http\Controllers\Sucursal\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\ShiftMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ShiftMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, ShiftMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveSucursalBranchId($request);
        $range = $this->resolveDateRange($request);
        $key = $meta->cacheKey('turnos', $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'daily_differences' => $service->dailyDifferences($range, $branchId, $tenantId),
            'shifts' => $service->shiftsTable($range, $branchId, $tenantId, 100),
        ]);

        return Inertia::render('Sucursal/Metricas/Turnos', [
            ...$this->commonProps($request, $range, $branchId),
            'data' => $data,
        ]);
    }
}
