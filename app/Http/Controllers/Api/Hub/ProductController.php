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
        $request->validate([
            'search' => 'nullable|string|max:100',
            // Incluye sale_mode + presentaciones (para agregar items por
            // presentación en la edición de ventas del admin).
            'with_presentations' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $search = trim((string) $request->input('search', ''));
        $withPresentations = $request->boolean('with_presentations');

        $products = Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $user->tenant_id)
            ->where('branch_id', $user->branch_id)
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->when($withPresentations, fn ($q) => $q->with(['presentations' => fn ($pq) => $pq->orderBy('id')]))
            ->orderBy('name')
            ->limit(50)
            ->get($withPresentations
                ? ['id', 'name', 'price', 'unit_type', 'sale_mode']
                : ['id', 'name', 'price', 'unit_type']);

        return response()->json([
            'data' => $products->map(fn ($p) => array_merge([
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'unit_type' => $p->unit_type,
            ], $withPresentations ? [
                'sale_mode' => $p->sale_mode,
                'presentations' => $p->presentations->map(fn ($pr) => [
                    'id' => $pr->id,
                    'name' => $pr->name,
                    'content' => (float) $pr->content,
                    'unit' => $pr->unit,
                    'price' => (float) $pr->price,
                ])->values(),
            ] : []))->values(),
        ]);
    }
}
