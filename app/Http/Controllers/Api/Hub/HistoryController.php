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
     * filtradas por fecha (por defecto hoy). Misma semántica que el
     * historial web de caja (Caja\HistorialController).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['date' => 'nullable|date']);

        $user = $request->user();
        $date = $request->input('date') ?: today()->toDateString();

        $saleIds = Payment::where('user_id', $user->id)->distinct()->pluck('sale_id');

        $sales = Sale::withoutGlobalScopes()
            ->where('branch_id', $user->branch_id)
            ->whereIn('id', $saleIds)
            ->whereDate('created_at', $date)
            ->with(['items', 'payments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return HubSaleResource::collection($sales);
    }
}
