<?php

namespace App\Http\Controllers\Api;

use App\Enums\SaleStatus;
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
            'items.*.presentation_id' => 'nullable|integer',
            'payment_method' => 'required|in:cash,card,transfer',
            'origin_name' => 'nullable|string|max:100',
        ]);

        $branchId = $request->branch_id;
        $tenantId = $request->tenant_id;

        // Validate monthly sales limit
        $tenant = app('tenant');
        $monthlySales = Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        if ($monthlySales >= $tenant->max_sales_per_branch_month) {
            return response()->json([
                'message' => 'Se alcanzo el limite de ventas mensuales para esta sucursal.',
            ], 429);
        }

        // Validate all products exist and are active in this branch
        $productIds = collect($request->items)->pluck('product_id')->unique();

        $products = Product::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('id', $productIds)
            ->with(['presentations' => fn ($q) => $q->where('status', 'active')])
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
            // Advisory lock per branch to prevent duplicate folios under concurrency
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchId]);

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
                $presentationId = $item['presentation_id'] ?? null;

                // Use presentation price when applicable
                if ($presentationId && in_array($product->sale_mode, ['presentation', 'both'])) {
                    $presentation = $product->presentations->find($presentationId);

                    if (! $presentation) {
                        throw new \InvalidArgumentException("Presentación {$presentationId} no válida para {$product->name}.");
                    }

                    $unitPrice = (float) $presentation->price;
                    $productName = $product->name . ' - ' . $presentation->name;
                } else {
                    $unitPrice = (float) $product->price;
                    $productName = $product->name;
                }

                $subtotal = round($quantity * $unitPrice, 2);
                $total += $subtotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $productName,
                    'unit_type' => $product->unit_type,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'original_unit_price' => $unitPrice,
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
                'origin_name' => $request->input('origin_name', 'Bascula'),
                'amount_paid' => 0,
                'amount_pending' => round($total, 2),
                'status' => SaleStatus::Active,
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
