<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\DailySummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HistoryController extends Controller
{
    /**
     * Historial de ventas de la sucursal, filtrable por fecha (hoy por defecto),
     * producto y rango de total. El alcance depende del rol, con la misma
     * semántica que la web:
     *
     *   - **admin-sucursal** → TODAS las ventas de la sucursal del día (fecha
     *     canónica COALESCE(completed_at, created_at); estados Completed/Pending/
     *     Fulfilled; excluye pedidos web pendientes). Paridad con
     *     Sucursal\SaleHistoryController.
     *   - **cajero** → solo las ventas donde registró al menos un pago. Paridad
     *     con Caja\HistorialController.
     */
    public function index(Request $request, DailySummaryService $daySummary): AnonymousResourceCollection
    {
        $request->validate([
            'date' => 'nullable|date',
            'search' => 'nullable|string|max:50',
            'product' => 'nullable|string|max:100',
            'min_total' => 'nullable|numeric|min:0',
            'max_total' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $date = $request->input('date') ?: today()->toDateString();
        $search = trim((string) $request->input('search', ''));
        $product = trim((string) $request->input('product', ''));
        $minTotal = $request->input('min_total');
        $maxTotal = $request->input('max_total');
        $isAdmin = $user->hasRole('admin-sucursal');

        // Filtros comunes a ambos roles: producto (por nombre en las partidas) y
        // rango de total.
        $applyFilters = fn ($q) => $q
            ->when($product !== '', fn ($qq) => $qq->whereHas(
                'items',
                fn ($iq) => $iq->where('product_name', 'ilike', '%'.addcslashes($product, '%_\\').'%')
            ))
            ->when(is_numeric($minTotal), fn ($qq) => $qq->where('total', '>=', (float) $minTotal))
            ->when(is_numeric($maxTotal), fn ($qq) => $qq->where('total', '<=', (float) $maxTotal));

        if ($isAdmin) {
            // Buscar por folio ignora fecha y estado: busca en todo el historial
            // de la sucursal (misma semántica que Sucursal\SaleHistoryController).
            $base = fn () => $applyFilters(
                Sale::withoutGlobalScopes()
                    ->where('branch_id', $user->branch_id)
                    // Excluye pedidos web todavía pendientes (viven en la Mesa de Trabajo).
                    ->where(fn ($q) => $q->where('origin', '!=', 'web')
                        ->orWhere('status', '!=', SaleStatus::Pending->value))
                    ->when(
                        $search !== '',
                        fn ($q) => $q->where('folio', 'ilike', "%{$search}%"),
                        fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, created_at)) = ?', [$date])
                            ->whereIn('status', [
                                SaleStatus::Completed->value,
                                SaleStatus::Pending->value,
                                SaleStatus::Fulfilled->value,
                            ]),
                    )
            );
        } else {
            $saleIds = Payment::where('user_id', $user->id)->distinct()->pluck('sale_id');
            $base = fn () => $applyFilters(
                Sale::withoutGlobalScopes()
                    ->where('branch_id', $user->branch_id)
                    ->whereIn('id', $saleIds)
                    ->when(
                        $search !== '',
                        fn ($q) => $q->where('folio', 'ilike', "%{$search}%"),
                        fn ($q) => $q->whereDate('created_at', $date),
                    )
            );
        }

        // Resumen del conjunto filtrado (no solo la página).
        $summary = [
            'count' => $base()->count(),
            'total' => round((float) $base()->sum('total'), 2),
        ];

        $sales = $base()
            ->with(['items', 'payments.user:id,name', 'payments.updatedByUser:id,name', 'customer:id,name,phone'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Resumen del día enriquecido (DaySummaryBar web) — solo para el admin,
        // vía la fuente única de verdad (DailySummaryService). Al buscar por
        // folio no aplica (la búsqueda ignora la fecha).
        $richSummary = null;
        if ($isAdmin && $search === '') {
            $branch = Branch::withoutGlobalScopes()->find($user->branch_id);
            $methods = $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'];
            $day = $daySummary->forDate($user->branch_id, $user->tenant_id, $date, $methods);
            $s = $day['sales'];
            $c = $day['collections'];

            $richSummary = [
                'date' => $date,
                'total_sold' => $s['net_sales'],
                'sale_count' => $s['ticket_count'],
                'avg_ticket' => $s['avg_ticket'],
                'cancelled_amount' => $s['cancelled_amount'],
                'cancelled_count' => $s['cancelled_count'],
                'total_collected' => $c['total'],
                'collected_from_today' => $c['from_today'],
                'collected_from_previous' => $c['from_previous'],
                'payment_count' => $c['payment_count'],
                'by_method' => $c['by_method'],
            ];
        }

        return HubSaleResource::collection($sales)->additional([
            'summary' => $summary,
            'day_summary' => $richSummary,
            'is_admin' => $isAdmin,
        ]);
    }
}
