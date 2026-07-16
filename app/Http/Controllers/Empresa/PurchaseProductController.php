<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Concerns\HandlesPurchaseProductCategoryWrites;
use App\Http\Controllers\Concerns\HandlesPurchaseProductWrites;
use App\Http\Controllers\Concerns\SerializesPurchaseProducts;
use App\Http\Controllers\Controller;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductCategory;
use App\Services\Purchases\PurchaseProductMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD del catálogo de productos de compra y sus categorías para admin-empresa.
 * Tenant-wide, igual que Proveedores. La escritura y la serialización se
 * comparten con el controlador de sucursal vía concerns; aquí vive además el
 * borrado de productos y categorías.
 */
class PurchaseProductController extends Controller
{
    use HandlesPurchaseProductCategoryWrites;
    use HandlesPurchaseProductWrites;
    use SerializesPurchaseProducts;

    public function index(Request $request): Response
    {
        return Inertia::render('Empresa/ProductosCompra/Index', $this->purchaseProductIndexData($request));
    }

    public function history(PurchaseProduct $producto_compra): JsonResponse
    {
        return $this->purchaseProductHistory($producto_compra);
    }

    public function destroy(PurchaseProduct $producto_compra): RedirectResponse
    {
        $tenant = app('tenant');
        if ($producto_compra->tenant_id !== $tenant->id) {
            abort(403);
        }

        $hasItems = DB::table('purchase_items')->where('purchase_product_id', $producto_compra->id)->exists();
        if ($hasItems) {
            return back()->withErrors([
                'producto' => 'No puedes eliminar un producto con compras. Márcalo como inactivo.',
            ]);
        }

        $producto_compra->delete();

        return back()->with('success', 'Producto de compra eliminado.');
    }

    /**
     * Borra una categoría; sus productos quedan sin categoría (FK nullOnDelete).
     */
    public function destroyCategory(PurchaseProductCategory $categoria): RedirectResponse
    {
        if ($categoria->tenant_id !== app('tenant')->id) {
            abort(403);
        }

        $categoria->delete();

        return back()->with('success', 'Categoría eliminada.');
    }

    public function mergeCandidates(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        $products = PurchaseProduct::query()
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']))
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'unit']);

        return response()->json(['data' => $products]);
    }

    public function mergePreview(Request $request, PurchaseProductMergeService $service): JsonResponse
    {
        [$canonical, $absorbedIds] = $this->validatedMergeInput($request);

        return response()->json($service->preview($canonical, $absorbedIds));
    }

    public function merge(Request $request, PurchaseProductMergeService $service): RedirectResponse
    {
        [$canonical, $absorbedIds] = $this->validatedMergeInput($request);

        $result = $service->merge($canonical, $absorbedIds);

        return back()->with('success', "Se fusionaron {$result['absorbed_count']} fichas en «{$canonical->name}» ({$result['relinked_items_count']} líneas reapuntadas).");
    }

    /**
     * Valida y resuelve el input de fusión, forzando pertenencia al tenant
     * tanto del canónico como de cada absorbido (defensa multi-tenant).
     *
     * @return array{0: PurchaseProduct, 1: array<int, int>}
     */
    private function validatedMergeInput(Request $request): array
    {
        $tenantId = app('tenant')->id;
        $inTenant = fn ($q) => $q->where('tenant_id', $tenantId);

        $validated = $request->validate([
            'canonical_id' => ['required', 'integer', Rule::exists('purchase_products', 'id')->where($inTenant)->whereNull('deleted_at')],
            'absorbed_ids' => ['required', 'array', 'min:1'],
            'absorbed_ids.*' => ['integer', Rule::exists('purchase_products', 'id')->where($inTenant)->whereNull('deleted_at')],
        ]);

        $canonical = PurchaseProduct::findOrFail($validated['canonical_id']);

        return [$canonical, $validated['absorbed_ids']];
    }
}
