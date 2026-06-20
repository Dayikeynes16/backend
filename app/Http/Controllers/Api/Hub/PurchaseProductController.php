<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\PurchaseProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo tenant-wide de productos de compra para el autocompletado del
 * formulario de compras del hub. Búsqueda por nombre.
 */
class PurchaseProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100']);

        $user = $request->user();
        $search = trim((string) $request->input('search', ''));

        $products = PurchaseProduct::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'unit']);

        return response()->json([
            'data' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit,
            ])->values(),
        ]);
    }
}
