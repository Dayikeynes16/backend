<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Concerns\SerializesPurchaseProducts;
use App\Http\Controllers\Controller;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo tenant-wide de productos de compra. `index` es la búsqueda para el
 * autocompletado del formulario de compras (ambos roles). La gestión del
 * catálogo (`manage`/store/update + categorías) es de admin-sucursal, paridad
 * con Sucursal\PurchaseProductController (sin borrado, reservado a empresa).
 */
class PurchaseProductController extends Controller
{
    use SerializesPurchaseProducts;

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

    /** Pantalla de gestión: lista paginada + categorías + stats (admin). */
    public function manage(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);

        return response()->json($this->purchaseProductIndexData($request));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);

        $validated = $this->validateProduct($request, $user->tenant_id);

        $product = PurchaseProduct::create(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'active',
            'created_by' => $user->id,
        ]));

        app(AuditLogger::class)->logCreated($product);

        return response()->json(['data' => $this->row($product->load('category'))], 201);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);

        $found = PurchaseProduct::where('tenant_id', $user->tenant_id)->findOrFail($product);

        $auditor = app(AuditLogger::class);
        $found->loadMissing('category');
        $before = $auditor->purchaseProductSnapshot($found);

        $validated = $this->validateProduct($request, $user->tenant_id, $found->id, withStatus: true);
        $found->update($validated);

        $found->load('category');
        $auditor->logUpdatedIfChanged($found, $before, $auditor->purchaseProductSnapshot($found));

        return response()->json(['data' => $this->row($found)]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);

        $validated = $this->validateCategory($request, $user->tenant_id);

        $category = PurchaseProductCategory::create(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'active',
            'created_by' => $user->id,
        ]));

        return response()->json(['data' => [
            'id' => $category->id, 'name' => $category->name, 'status' => $category->status, 'products_count' => 0,
        ]], 201);
    }

    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);

        $found = PurchaseProductCategory::where('tenant_id', $user->tenant_id)->findOrFail($category);

        $validated = $this->validateCategory($request, $user->tenant_id, $found->id, withStatus: true);
        $found->update($validated);

        return response()->json(['data' => [
            'id' => $found->id, 'name' => $found->name, 'status' => $found->status,
            'products_count' => $found->products()->count(),
        ]]);
    }

    public function history(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);

        $found = PurchaseProduct::where('tenant_id', $user->tenant_id)->findOrFail($product);

        return $this->purchaseProductHistory($found);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function ensureAdmin(User $user): void
    {
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede gestionar el catálogo de compras.'
        );
    }

    /** @return array<string, mixed> */
    private function validateProduct(Request $request, int $tenantId, ?int $ignoreId = null, bool $withStatus = false): array
    {
        $nameRule = Rule::unique('purchase_products', 'name')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'));
        if ($ignoreId) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $rules = [
            'name' => ['required', 'string', 'max:160', $nameRule],
            'unit' => 'required|string|max:10',
            'purchase_product_category_id' => ['nullable', Rule::exists('purchase_product_categories', 'id')->where('tenant_id', $tenantId)],
        ];
        if ($withStatus) {
            $rules['status'] = 'required|in:active,inactive';
        }

        return $request->validate($rules, ['name.unique' => 'Ya existe un producto de compra con ese nombre.']);
    }

    /** @return array<string, mixed> */
    private function validateCategory(Request $request, int $tenantId, ?int $ignoreId = null, bool $withStatus = false): array
    {
        $nameRule = Rule::unique('purchase_product_categories', 'name')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId));
        if ($ignoreId) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $rules = ['name' => ['required', 'string', 'max:120', $nameRule]];
        if ($withStatus) {
            $rules['status'] = 'required|in:active,inactive';
        }

        return $request->validate($rules, ['name.unique' => 'Ya existe una categoría con ese nombre.']);
    }

    /** @return array<string, mixed> */
    private function row(PurchaseProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'unit' => $p->unit,
            'category_id' => $p->purchase_product_category_id,
            'category_label' => $p->category?->name,
            'status' => $p->status,
            'purchase_items_count' => (int) ($p->purchase_items_count ?? 0),
            'last_edited' => null,
        ];
    }
}
