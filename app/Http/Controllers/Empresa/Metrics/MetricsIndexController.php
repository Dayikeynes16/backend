<?php

namespace App\Http\Controllers\Empresa\Metrics;

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
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);

        return Inertia::render('Empresa/Metricas/Index', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
        ]);
    }
}
