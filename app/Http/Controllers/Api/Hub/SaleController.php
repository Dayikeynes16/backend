<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
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
            ->with(['items', 'payments'])
            ->findOrFail($sale);

        return HubSaleResource::make($found);
    }
}
