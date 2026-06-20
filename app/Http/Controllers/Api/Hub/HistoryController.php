<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HistoryController extends Controller
{
    /**
     * Ventas en las que el usuario del token registró al menos un pago,
     * filtradas por fecha (por defecto hoy), producto y rango de total. Misma
     * semántica que el historial web de caja (Caja\HistorialController), más un
     * resumen del día y paginación.
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
        $date = $request->input('date') ?: today()->toDateString();
        $product = trim((string) $request->input('product', ''));
        $minTotal = $request->input('min_total');
        $maxTotal = $request->input('max_total');

        $saleIds = Payment::where('user_id', $user->id)->distinct()->pluck('sale_id');

        $base = fn () => Sale::withoutGlobalScopes()
            ->where('branch_id', $user->branch_id)
            ->whereIn('id', $saleIds)
            ->whereDate('created_at', $date)
            ->when($product !== '', fn ($q) => $q->whereHas(
                'items',
                fn ($iq) => $iq->where('product_name', 'ilike', '%'.addcslashes($product, '%_\\').'%')
            ))
            ->when(is_numeric($minTotal), fn ($q) => $q->where('total', '>=', (float) $minTotal))
            ->when(is_numeric($maxTotal), fn ($q) => $q->where('total', '<=', (float) $maxTotal));

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
