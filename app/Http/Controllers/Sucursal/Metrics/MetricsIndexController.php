<?php

namespace App\Http\Controllers\Sucursal\Metrics;

use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetricsIndexController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveSucursalBranchId($request);
        $range = $this->resolveDateRange($request);

        return Inertia::render('Sucursal/Metricas/Index', [
            ...$this->commonProps($request, $range, $branchId),
        ]);
    }
}
