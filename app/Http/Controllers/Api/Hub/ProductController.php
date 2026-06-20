<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de productos activos de la sucursal (apoyo para formularios del hub,
 * p.ej. precios preferenciales). Búsqueda por nombre.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100']);

        $user = $request->user();
        $search = trim((string) $request->input('search', ''));

        $products = Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $user->tenant_id)
            ->where('branch_id', $user->branch_id)
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'price', 'unit_type']);

        return response()->json([
            'data' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'unit_type' => $p->unit_type,
            ])->values(),
        ]);
    }
}
