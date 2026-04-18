<?php

namespace App\Http\Controllers\Sucursal\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetricsIndexController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, MetricsService $service): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveSucursalBranchId($request);
        $range = $this->resolveDateRange($request);

        $data = $service->dashboardSummary($range, $branchId, $tenantId, $this->wantsRefresh($request));

        return Inertia::render('Sucursal/Metricas/Index', [
            ...$this->commonProps($request, $range, $branchId),
            'data' => $data,
            'backfill_run_at' => $service->backfillDate(),
        ]);
    }
}
