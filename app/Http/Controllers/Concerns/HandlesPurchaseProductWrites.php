<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PurchaseProduct;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Escritura del catálogo de productos de compra (tenant-wide). Compartido por
 * el controlador de empresa y el de sucursal — el catálogo es el mismo; el
 * acceso del admin-sucursal se gatea por el toggle
 * `branch_admin_purchase_products_enabled`. El borrado (destroy) NO vive aquí:
 * queda reservado a empresa/superadmin. Cada escritura registra el historial.
 */
trait HandlesPurchaseProductWrites
{
    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $this->validatedPurchaseProductRequest($request, $tenant->id);

        $product = PurchaseProduct::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]));

        app(AuditLogger::class)->logCreated($product);

        return back()->with('success', 'Producto de compra creado.');
    }

    public function update(Request $request, PurchaseProduct $producto_compra): RedirectResponse
    {
        $tenant = app('tenant');
        if ($producto_compra->tenant_id !== $tenant->id) {
            abort(403);
        }

        $auditor = app(AuditLogger::class);
        $producto_compra->loadMissing('category');
        $before = $auditor->purchaseProductSnapshot($producto_compra);

        $validated = $this->validatedPurchaseProductRequest($request, $tenant->id, $producto_compra->id, withStatus: true);
        $producto_compra->update($validated);

        $producto_compra->load('category');
        $auditor->logUpdatedIfChanged($producto_compra, $before, $auditor->purchaseProductSnapshot($producto_compra));

        return back()->with('success', 'Producto de compra actualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPurchaseProductRequest(Request $request, int $tenantId, ?int $ignoreId = null, bool $withStatus = false): array
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

        return $request->validate($rules, [
            'name.unique' => 'Ya existe un producto de compra con ese nombre.',
        ]);
    }
}
