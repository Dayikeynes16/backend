<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Payment;
use App\Models\Sale;
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
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'date' => 'nullable|date',
            'product' => 'nullable|string|max:100',
            'min_total' => 'nullable|numeric|min:0',
            'max_total' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $date = $request->input('date') ?: today()->toDateString();
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
            $base = fn () => $applyFilters(
                Sale::withoutGlobalScopes()
                    ->where('branch_id', $user->branch_id)
                    // Excluye pedidos web todavía pendientes (viven en la Mesa de Trabajo).
                    ->where(fn ($q) => $q->where('origin', '!=', 'web')
                        ->orWhere('status', '!=', SaleStatus::Pending->value))
                    ->whereRaw('DATE(COALESCE(completed_at, created_at)) = ?', [$date])
                    ->whereIn('status', [
                        SaleStatus::Completed->value,
                        SaleStatus::Pending->value,
                        SaleStatus::Fulfilled->value,
                    ])
            );
        } else {
            $saleIds = Payment::where('user_id', $user->id)->distinct()->pluck('sale_id');
            $base = fn () => $applyFilters(
                Sale::withoutGlobalScopes()
                    ->where('branch_id', $user->branch_id)
                    ->whereIn('id', $saleIds)
                    ->whereDate('created_at', $date)
            );
        }

        // Resumen del día sobre TODO el conjunto filtrado (no solo la página).
        $summary = [
            'count' => $base()->count(),
            'total' => round((float) $base()->sum('total'), 2),
        ];

        $sales = $base()
            ->with(['items', 'payments', 'customer:id,name,phone'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return HubSaleResource::collection($sales)->additional(['summary' => $summary]);
    }
}
