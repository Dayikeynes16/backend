<?php

namespace App\Http\Controllers\Empresa;

use App\Enums\PurchaseProductCategory;
use App\Http\Controllers\Controller;
use App\Models\PurchaseProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD del catálogo de productos de compra para admin-empresa. Tenant-wide,
 * igual que Proveedores.
 */
class PurchaseProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('q', ''));
        $categoryFilter = $request->input('category');
        $statusFilter = $request->input('status', 'active');

        $products = PurchaseProduct::query()
            ->withCount('purchaseItems')
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when($categoryFilter, fn ($q) => $q->where('category', $categoryFilter))
            ->when($statusFilter !== 'all', fn ($q) => $q->where('status', $statusFilter))
            ->orderBy('name')
            ->get()
            ->map(fn (PurchaseProduct $p) => $this->serialize($p));

        return Inertia::render('Empresa/ProductosCompra/Index', [
            'products' => $products,
            'filters' => ['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter],
            'categories' => array_map(fn (PurchaseProductCategory $c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ], PurchaseProductCategory::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $validated = $this->validated($request, $tenant->id);

        PurchaseProduct::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]));

        return back()->with('success', 'Producto de compra creado.');
    }

    public function update(Request $request, PurchaseProduct $producto_compra): RedirectResponse
    {
        $tenant = app('tenant');
        if ($producto_compra->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $this->validated($request, $tenant->id, $producto_compra->id, withStatus: true);
        $producto_compra->update($validated);

        return back()->with('success', 'Producto de compra actualizado.');
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
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $tenantId, ?int $ignoreId = null, bool $withStatus = false): array
    {
        $nameRule = Rule::unique('purchase_products', 'name')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'));
        if ($ignoreId) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $rules = [
            'name' => ['required', 'string', 'max:160', $nameRule],
            'unit' => 'required|string|max:10',
            'category' => ['nullable', Rule::enum(PurchaseProductCategory::class)],
        ];
        if ($withStatus) {
            $rules['status'] = 'required|in:active,inactive';
        }

        return $request->validate($rules, [
            'name.unique' => 'Ya existe un producto de compra con ese nombre.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PurchaseProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'unit' => $p->unit,
            'category' => $p->category instanceof PurchaseProductCategory ? $p->category->value : $p->category,
            'category_label' => $p->category instanceof PurchaseProductCategory ? $p->category->label() : null,
            'status' => $p->status,
            'purchase_items_count' => (int) ($p->purchase_items_count ?? 0),
        ];
    }
}
