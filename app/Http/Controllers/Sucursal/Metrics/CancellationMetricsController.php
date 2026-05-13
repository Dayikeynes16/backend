<?php

namespace App\Http\Controllers\Sucursal\Metrics;

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
        $branchId = $this->resolveSucursalBranchId($request);
        $range = $this->resolveDateRange($request);
        $key = $meta->cacheKey('cancelaciones', $range, $branchId, $tenantId);

        if ($this->wantsRefresh($request)) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => [
            'summary' => $service->summary($range, $branchId, $tenantId),
            'daily' => $service->daily($range, $branchId, $tenantId),
            'previous_daily' => $service->daily($range->previousComparable(), $branchId, $tenantId),
            'by_reason' => $service->byReason($range, $branchId, $tenantId),
            'by_cashier' => $service->byCashier($range, $branchId, $tenantId),
        ]);

        // Historial detallado: no cacheado (cursor + joins), igual que Historial.
        $history = Sale::where('branch_id', $branchId)
            ->where('status', SaleStatus::Cancelled->value)
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->with([
                'cancelledByUser:id,name',
                'cancelRequestedByUser:id,name',
                'items',
                'customer:id,name',
            ])
            ->orderByDesc('cancelled_at')
            ->cursorPaginate(20)
            ->withQueryString();

        return Inertia::render('Sucursal/Metricas/Cancelaciones', [
            ...$this->commonProps($request, $range, $branchId),
            'data' => $data,
            'history' => $history,
        ]);
    }
}
