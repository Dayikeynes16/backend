<?php

namespace Tests\Feature\Sucursal;

use App\Models\ExpenseCategory;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Delegación de catálogos tenant-wide (proveedores y categorías de gasto) al
 * admin-sucursal, gobernada por los toggles por sucursal que activa la empresa.
 */
class BranchAdminCatalogTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    // --- Proveedores ---------------------------------------------------------

    public function test_provider_store_is_forbidden_when_toggle_off(): void
    {
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.proveedores.store', $this->tenant->slug), [
            'name' => 'Carnes Don Pedro',
            'type' => 'mayorista_carne',
        ])->assertForbidden();

        $this->assertDatabaseCount('providers', 0);
    }

    public function test_provider_store_and_update_succeed_when_toggle_on(): void
    {
        $this->branch->update(['branch_admin_providers_enabled' => true]);
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.proveedores.store', $this->tenant->slug), [
            'name' => 'Carnes Don Pedro',
            'type' => 'mayorista_carne',
        ])->assertSessionHasNoErrors();

        $provider = Provider::firstOrFail();
        $this->assertSame($this->tenant->id, $provider->tenant_id);
        $this->assertSame('Carnes Don Pedro', $provider->name);

        $this->put(route('sucursal.proveedores.update', [$this->tenant->slug, $provider->id]), [
            'name' => 'Carnes Don Pedro S.A.',
            'type' => 'insumos',
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Carnes Don Pedro S.A.', $provider->fresh()->name);
    }

    public function test_sucursal_cannot_delete_providers(): void
    {
        $this->assertFalse(Route::has('sucursal.proveedores.destroy'));
    }

    // --- Categorías / subcategorías ------------------------------------------

    public function test_category_store_is_forbidden_when_toggle_off(): void
    {
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.gastos.categorias.store', $this->tenant->slug), [
            'name' => 'Servicios',
        ])->assertForbidden();

        $this->assertDatabaseCount('expense_categories', 0);
    }

    public function test_category_and_subcategory_store_succeed_when_toggle_on(): void
    {
        $this->branch->update(['branch_admin_expense_categories_enabled' => true]);
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.gastos.categorias.store', $this->tenant->slug), [
            'name' => 'Servicios',
        ])->assertSessionHasNoErrors();

        $category = ExpenseCategory::firstOrFail();
        $this->assertSame($this->tenant->id, $category->tenant_id);

        $this->post(route('sucursal.gastos.subcategorias.store', $this->tenant->slug), [
            'expense_category_id' => $category->id,
            'name' => 'Luz',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('expense_subcategories', [
            'expense_category_id' => $category->id,
            'name' => 'Luz',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_category_update_succeeds_when_toggle_on(): void
    {
        $this->branch->update(['branch_admin_expense_categories_enabled' => true]);
        $category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Servicios',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminSucursal);

        $this->put(route('sucursal.gastos.categorias.update', [$this->tenant->slug, $category->id]), [
            'name' => 'Servicios generales',
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Servicios generales', $category->fresh()->name);
    }

    public function test_subcategory_store_is_forbidden_when_toggle_off(): void
    {
        $category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Servicios',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.gastos.subcategorias.store', $this->tenant->slug), [
            'expense_category_id' => $category->id,
            'name' => 'Luz',
        ])->assertForbidden();

        $this->assertDatabaseCount('expense_subcategories', 0);
    }

    public function test_ai_category_draft_is_forbidden_when_toggle_off(): void
    {
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'gastos de gasolina y mantenimiento',
        ])->assertForbidden();
    }

    public function test_sucursal_cannot_delete_categories_or_subcategories(): void
    {
        $this->assertFalse(Route::has('sucursal.gastos.categorias.destroy'));
        $this->assertFalse(Route::has('sucursal.gastos.subcategorias.destroy'));
    }

    // --- index expone el flag al frontend ------------------------------------

    public function test_index_exposes_can_manage_flags(): void
    {
        $this->branch->update([
            'branch_admin_providers_enabled' => true,
            'branch_admin_expense_categories_enabled' => true,
        ]);
        $this->actingAs($this->adminSucursal);

        $providers = $this->get(route('sucursal.proveedores.index', $this->tenant->slug));
        $providers->assertOk();
        $this->assertTrue($providers->viewData('page')['props']['canManage']);

        $gastos = $this->get(route('sucursal.gastos.index', $this->tenant->slug));
        $gastos->assertOk();
        $this->assertTrue($gastos->viewData('page')['props']['canManageCategories']);
    }
}
