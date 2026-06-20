<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = Sale::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->whereIn('status', [SaleStatus::Active, SaleStatus::Pending])
            ->with('items')
            ->orderByDesc('created_at')
            ->paginate(30);

        return HubSaleResource::collection($sales);
    }

    public function show(Request $request, int $sale): HubSaleResource
    {
        $found = Sale::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->with(['items', 'payments', 'customer'])
            ->findOrFail($sale);

        $branch = Branch::withoutGlobalScopes()->find($request->user()->branch_id);

        // Los métodos de pago habilitados de la sucursal viajan junto al detalle
        // para que el hub no los hardcodee (evita ofrecer un método que el
        // backend rechazaría con 422).
        return HubSaleResource::make($found)->additional([
            'payment_methods' => $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'],
        ]);
    }
}
