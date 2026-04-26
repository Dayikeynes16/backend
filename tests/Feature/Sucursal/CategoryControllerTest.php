<?php

namespace Tests\Feature\Sucursal;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_legacy_index_route_redirects_to_productos_with_categorias_tab(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->get(route('sucursal.categorias.index', $this->tenant->slug));

        $response->assertStatus(301);
        $response->assertRedirect(route('sucursal.productos.index', [$this->tenant->slug, 'tab' => 'categorias']));
    }

    public function test_destroy_blocks_when_category_has_products(): void
    {
        $this->actingAs($this->adminSucursal);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Res',
            'status' => 'active',
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'Bistec',
            'price' => 180,
            'unit_type' => 'kg',
            'sale_mode' => 'weight',
            'status' => 'active',
        ]);

        $response = $this->delete(route('sucursal.categorias.destroy', [$this->tenant->slug, $category->id]));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_destroy_succeeds_when_category_has_no_products(): void
    {
        $this->actingAs($this->adminSucursal);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Vacía',
            'status' => 'active',
        ]);

        $response = $this->delete(route('sucursal.categorias.destroy', [$this->tenant->slug, $category->id]));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_store_blocks_duplicate_name_within_same_branch(): void
    {
        $this->actingAs($this->adminSucursal);

        Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Pollo',
            'status' => 'active',
        ]);

        $response = $this->from(route('sucursal.productos.index', [$this->tenant->slug, 'tab' => 'categorias']))
            ->post(route('sucursal.categorias.store', $this->tenant->slug), ['name' => 'Pollo']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Category::where('branch_id', $this->branch->id)->where('name', 'Pollo')->count());
    }

    public function test_store_allows_same_name_in_different_branch(): void
    {
        $this->actingAs($this->adminSucursal);

        Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'name' => 'Pollo',
            'status' => 'active',
        ]);

        $response = $this->post(route('sucursal.categorias.store', $this->tenant->slug), ['name' => 'Pollo']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $this->assertSame(1, Category::where('branch_id', $this->branch->id)->where('name', 'Pollo')->count());
    }

    public function test_update_blocks_duplicate_name(): void
    {
        $this->actingAs($this->adminSucursal);

        Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Res',
            'status' => 'active',
        ]);

        $other = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cerdo',
            'status' => 'active',
        ]);

        $response = $this->from(route('sucursal.productos.index', [$this->tenant->slug, 'tab' => 'categorias']))
            ->put(route('sucursal.categorias.update', [$this->tenant->slug, $other->id]), [
                'name' => 'Res',
                'status' => 'active',
            ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_update_allows_keeping_same_name_for_same_record(): void
    {
        $this->actingAs($this->adminSucursal);

        $cat = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Embutidos',
            'status' => 'active',
        ]);

        $response = $this->put(route('sucursal.categorias.update', [$this->tenant->slug, $cat->id]), [
            'name' => 'Embutidos',
            'status' => 'inactive',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('inactive', $cat->fresh()->status);
    }

    public function test_productos_index_includes_categories_for_tab_with_count(): void
    {
        $this->actingAs($this->adminSucursal);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Aves',
            'status' => 'active',
        ]);
        Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'Pollo entero',
            'price' => 110,
            'unit_type' => 'kg',
            'sale_mode' => 'weight',
            'status' => 'active',
        ]);

        $response = $this->get(route('sucursal.productos.index', $this->tenant->slug));

        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertArrayHasKey('categoriesForTab', $page['props']);
        $this->assertCount(1, $page['props']['categoriesForTab']);
        $this->assertSame(1, $page['props']['categoriesForTab'][0]['products_count']);
    }
}
