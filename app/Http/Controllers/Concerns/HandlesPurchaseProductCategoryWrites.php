<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PurchaseProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Escritura del catálogo de categorías de productos de compra (tenant-wide).
 * Compartido por empresa y sucursal — ambos roles pueden crear/editar.
 * El borrado (destroyCategory) NO vive aquí: queda reservado a empresa.
 */
trait HandlesPurchaseProductCategoryWrites
{
    public function storeCategory(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $this->validatedCategoryRequest($request, $tenant->id);

        PurchaseProductCategory::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]));

        return back()->with('success', 'Categoría creada.');
    }

    public function updateCategory(Request $request, PurchaseProductCategory $categoria): RedirectResponse
    {
        $tenant = app('tenant');
        if ($categoria->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $this->validatedCategoryRequest($request, $tenant->id, $categoria->id, withStatus: true);
        $categoria->update($validated);

        return back()->with('success', 'Categoría actualizada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCategoryRequest(Request $request, int $tenantId, ?int $ignoreId = null, bool $withStatus = false): array
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

        return $request->validate($rules, [
            'name.unique' => 'Ya existe una categoría con ese nombre.',
        ]);
    }
}
