<?php

namespace Tests\Feature\Sucursal;

use App\Models\PurchaseProduct;
use App\Models\PurchaseProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Acceso del admin-sucursal al catálogo de productos de compra, gobernado por
 * el toggle por sucursal `branch_admin_purchase_products_enabled`. Con el toggle
 * apagado el módulo está totalmente oculto (incluida la lectura).
 */
class PurchaseProductBranchAdminTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function product(array $attrs = []): PurchaseProduct
    {
        return PurchaseProduct::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Canal de res',
            'unit' => 'kg',
            'status' => 'active',
        ], $attrs));
    }

    public function test_index_is_forbidden_when_toggle_off(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->get(route('sucursal.productos-compra.index', $this->tenant->slug))->assertForbidden();
    }

    public function test_store_update_and_history_are_forbidden_when_toggle_off(): void
    {
        $product = $this->product();
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.productos-compra.store', $this->tenant->slug), [
            'name' => 'Pierna de cerdo', 'unit' => 'kg',
        ])->assertForbidden();

        $this->put(route('sucursal.productos-compra.update', [$this->tenant->slug, $product->id]), [
            'name' => 'Otro', 'unit' => 'kg', 'status' => 'active',
        ])->assertForbidden();

        $this->get(route('sucursal.productos-compra.historial', [$this->tenant->slug, $product->id]))->assertForbidden();

        $this->assertSame(1, PurchaseProduct::count());
    }

    public function test_index_store_and_update_succeed_when_toggle_on(): void
    {
        $this->branch->update(['branch_admin_purchase_products_enabled' => true]);
        $this->actingAs($this->adminSucursal);

        $index = $this->get(route('sucursal.productos-compra.index', $this->tenant->slug));
        $index->assertOk();
        $this->assertTrue($index->viewData('page')['props']['canManage']);

        $cat = PurchaseProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Res', 'status' => 'active']);

        $this->post(route('sucursal.productos-compra.store', $this->tenant->slug), [
            'name' => 'Costilla de res', 'unit' => 'kg', 'purchase_product_category_id' => $cat->id,
        ])->assertSessionHasNoErrors();

        $product = PurchaseProduct::where('name', 'Costilla de res')->firstOrFail();
        $this->assertSame($this->tenant->id, $product->tenant_id);
        $this->assertSame($cat->id, $product->purchase_product_category_id);

        // El historial registra al usuario de sucursal que creó.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'event' => 'created',
            'user_id' => $this->adminSucursal->id,
        ]);

        $this->put(route('sucursal.productos-compra.update', [$this->tenant->slug, $product->id]), [
            'name' => 'Costilla de res', 'unit' => 'kg', 'purchase_product_category_id' => $cat->id, 'status' => 'inactive',
        ])->assertSessionHasNoErrors();

        $this->assertSame('inactive', $product->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'event' => 'updated',
            'user_id' => $this->adminSucursal->id,
        ]);
    }

    public function test_sucursal_cannot_delete_purchase_products(): void
    {
        $this->assertFalse(Route::has('sucursal.productos-compra.destroy'));
    }

    public function test_category_store_is_forbidden_when_toggle_off(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.productos-compra.categorias.store', $this->tenant->slug), [
            'name' => 'Embutidos',
        ])->assertForbidden();

        $this->assertDatabaseCount('purchase_product_categories', 0);
    }

    public function test_category_store_and_update_succeed_when_toggle_on(): void
    {
        $this->branch->update(['branch_admin_purchase_products_enabled' => true]);
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.productos-compra.categorias.store', $this->tenant->slug), [
            'name' => 'Embutidos',
        ])->assertSessionHasNoErrors();

        $category = PurchaseProductCategory::firstOrFail();
        $this->assertSame($this->tenant->id, $category->tenant_id);

        $this->put(route('sucursal.productos-compra.categorias.update', [$this->tenant->slug, $category->id]), [
            'name' => 'Embutidos y fiambres', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Embutidos y fiambres', $category->fresh()->name);
    }

    public function test_sucursal_cannot_delete_categories(): void
    {
        $this->assertFalse(Route::has('sucursal.productos-compra.categorias.destroy'));
    }

    public function test_superadmin_bypasses_toggle(): void
    {
        $superadmin = $this->makeUser('super@test.local', 'superadmin', null);
        $this->actingAs($superadmin);

        // Toggle apagado, pero superadmin pasa siempre.
        $this->get(route('sucursal.productos-compra.index', $this->tenant->slug))->assertOk();
    }
}
