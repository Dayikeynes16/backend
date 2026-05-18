<?php

namespace App\Http\Controllers\Empresa\Metrics;

use App\Enums\SaleStatus;
use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Metrics\CancellationMetrics;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class CancellationMetricsController extends Controller
{
    use ResolvesMetricsRequest;

    public function __invoke(Request $request, CancellationMetrics $service, MetricsService $meta): Response
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveEmpresaBranchId($request, $tenantId);
        $range = $this->resolveDateRange($request);
        $key = $meta->cacheKey('cancelaciones', $range, $branchId, $tenantId);
        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'daily' => $service->daily($range, $branchId, $tenantId),
            'previous_daily' => $service->daily($range->previousComparable(), $branchId, $tenantId),
            'by_reason' => $service->byReason($range, $branchId, $tenantId),
            'by_cashier' => $service->byCashier($range, $branchId, $tenantId),
            // Por sucursal: SIEMPRE sobre todas las sucursales del tenant
            // (independiente del filtro de sucursal, igual que se hace en
            // otras vistas de empresa donde el desglose por sucursal tiene
            // valor incluso cuando se está mirando solo una).
            'by_branch' => $service->byBranch($range, $tenantId),
        ]);

        $history = Sale::where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Cancelled->value)
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->with([
                'branch:id,name',
                'cancelledByUser:id,name',
                'cancelRequestedByUser:id,name',
                'items',
                'customer:id,name',
            ])
            ->orderByDesc('cancelled_at')
            ->cursorPaginate(20)
            ->withQueryString();

        return Inertia::render('Empresa/Metricas/Cancelaciones', [
            ...$this->commonProps($request, $range, $branchId),
            'branches' => $this->branchOptions($tenantId),
            'data' => $data,
            'history' => $history,
        ]);
    }
}
