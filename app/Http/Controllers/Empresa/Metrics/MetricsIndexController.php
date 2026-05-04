<?php

namespace App\Http\Controllers\Empresa\Metrics;

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
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);
        $statuses = $this->resolveStatuses($request);

        $data = $service->dashboardSummary($range, $branchId, $tenantId, $this->wantsRefresh($request), $statuses);

        return Inertia::render('Empresa/Metricas/Index', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'data' => $data,
            'backfill_run_at' => $service->backfillDate(),
        ]);
    }
}
