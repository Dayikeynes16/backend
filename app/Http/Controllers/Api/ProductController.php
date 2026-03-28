<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        try {
            $products = Product::withoutGlobalScopes()
                ->where('branch_id', $request->branch_id)
                ->where('status', 'active')
                ->where('visibility', 'public')
                ->with([
                    'presentations' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order'),
                ])
                ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
                ->when($request->unit_type, fn ($q, $t) => $q->where('unit_type', $t))
                ->orderBy('name')
                ->paginate(20);

            return ProductResource::collection($products);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => basename($e->getFile()).':'.$e->getLine(),
            ], 500);
        }
    }
}
