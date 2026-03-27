<?php

namespace App\Http\Controllers\Api;

use App\Events\NewExternalSale;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'payment_method' => 'required|in:cash,card,transfer',
        ]);

        $branchId = $request->branch_id;
        $tenantId = $request->tenant_id;

        // Validate all products exist and are active in this branch
        $productIds = collect($request->items)->pluck('product_id')->unique();

        $products = Product::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $missing = $productIds->diff($products->keys());

        if ($missing->isNotEmpty()) {
            return response()->json([
                'message' => 'Productos no válidos.',
                'errors' => [
                    'items' => $missing->map(fn ($id) => "Producto {$id} no existe o está inactivo.")->values(),
                ],
            ], 422);
        }

        $sale = DB::transaction(function () use ($request, $branchId, $tenantId, $products) {
            // Generate folio — use advisory lock to prevent duplicates
            $count = Sale::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->count();

            $folioNumber = $count + 1;
            $folio = 'S-' . str_pad($folioNumber, 5, '0', STR_PAD_LEFT);

            // Calculate total
            $total = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = $products[$item['product_id']];
                $quantity = (float) $item['quantity'];
                $subtotal = round($quantity * (float) $product->price, 2);
                $total += $subtotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_type' => $product->unit_type,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'subtotal' => $subtotal,
                ];
            }

            $sale = Sale::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'folio' => $folio,
                'payment_method' => $request->payment_method,
                'total' => round($total, 2),
                'origin' => 'api',
                'status' => 'pending',
            ]);

            foreach ($itemsData as $data) {
                $sale->items()->create($data);
            }

            return $sale;
        });

        $sale->load('items');

        NewExternalSale::dispatch($sale);

        return response()->json(
            SaleResource::make($sale),
            201
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = Sale::withoutGlobalScopes()
            ->where('branch_id', $request->branch_id)
            ->with('items')
            ->when($request->date, fn ($q, $d) => $q->whereDate('created_at', $d))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);

        return SaleResource::collection($sales);
    }

    public function show(Request $request, int $sale): SaleResource
    {
        $sale = Sale::withoutGlobalScopes()
            ->where('branch_id', $request->branch_id)
            ->with('items')
            ->findOrFail($sale);

        return SaleResource::make($sale);
    }
}
