<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProductManageApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('hub')->plainTextToken;
    }

    private function category(string $name = 'Res'): Category
    {
        return Category::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'name' => $name, 'status' => 'active',
        ]);
    }

    public function test_cajero_cannot_manage_catalog(): void
    {
        $token = $this->tokenFor($this->cajero);

        $this->withToken($token)->getJson('/api/v1/hub/products/manage')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/hub/products', ['name' => 'X', 'price' => 10, 'sale_mode' => 'weight', 'visibility' => 'public'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/hub/product-categories', ['name' => 'Y'])->assertForbidden();
    }

    public function test_admin_manage_lists_products_categories_and_stats(): void
    {
        $cat = $this->category();
        $this->makeProduct(['name' => 'Bistec', 'category_id' => $cat->id, 'status' => 'active']);
        $this->makeProduct(['name' => 'Chuleta', 'status' => 'inactive']);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson('/api/v1/hub/products/manage')
            ->assertOk();

        $this->assertSame(2, $res->json('stats.total'));
        $this->assertSame(1, $res->json('stats.active'));
        $this->assertCount(2, $res->json('data'));
        $this->assertNotEmpty($res->json('category_rows'));
    }

    public function test_admin_creates_weight_product(): void
    {
        $cat = $this->category();

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/products', [
                'name' => 'Arrachera', 'price' => 250, 'cost_price' => 180,
                'sale_mode' => 'weight', 'visibility' => 'public', 'category_id' => $cat->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Arrachera')
            ->assertJsonPath('data.unit_type', 'kg')
            ->assertJsonPath('data.category_label', 'Res');

        $this->assertDatabaseHas('products', ['name' => 'Arrachera', 'branch_id' => $this->branch->id]);
    }

    public function test_presentation_product_requires_presentations(): void
    {
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/products', [
                'name' => 'Queso', 'price' => 100, 'sale_mode' => 'presentation', 'visibility' => 'public',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['presentations']);
    }

    public function test_admin_creates_presentation_product_with_rows(): void
    {
        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/products', [
                'name' => 'Queso Oaxaca', 'price' => 100, 'sale_mode' => 'presentation', 'visibility' => 'public',
                'presentations' => [
                    ['name' => 'Bolsa 500g', 'content' => 500, 'unit' => 'g', 'price' => 60],
                    ['name' => 'Bolsa 1kg', 'content' => 1, 'unit' => 'kg', 'price' => 110],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.unit_type', 'piece')
            ->assertJsonCount(2, 'data.presentations');

        $id = $res->json('data.id');
        $this->assertSame(2, Product::find($id)->presentations()->count());
    }

    public function test_update_recreates_presentations(): void
    {
        $product = $this->makeProduct(['name' => 'Jamón', 'sale_mode' => 'presentation', 'unit_type' => 'piece']);
        $product->presentations()->create(['name' => 'Vieja', 'content' => 200, 'unit' => 'g', 'price' => 40, 'sort_order' => 0]);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->post("/api/v1/hub/products/{$product->id}", [
                'name' => 'Jamón', 'price' => 90, 'sale_mode' => 'presentation', 'visibility' => 'public', 'status' => 'active',
                'presentations' => [
                    ['name' => 'Nueva 300g', 'content' => 300, 'unit' => 'g', 'price' => 55],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.presentations')
            ->assertJsonPath('data.presentations.0.name', 'Nueva 300g');
    }

    public function test_quick_toggle_status(): void
    {
        $product = $this->makeProduct(['status' => 'active']);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/products/{$product->id}/quick", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame('inactive', $product->refresh()->status);
    }

    public function test_destroy_blocked_when_recent_sales(): void
    {
        $product = $this->makeProduct(['name' => 'Con ventas']);
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'folio' => 'S-1', 'payment_method' => 'cash', 'total' => 100,
            'amount_paid' => 100, 'amount_pending' => 0, 'origin' => 'api', 'status' => SaleStatus::Completed,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Con ventas',
            'unit_type' => 'kg', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100,
        ]);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/products/{$product->id}")
            ->assertStatus(422);

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_destroy_soft_deletes_without_recent_sales(): void
    {
        $product = $this->makeProduct(['name' => 'Sin ventas']);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('action', 'deleted');

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_category_crud_and_delete_block(): void
    {
        $token = $this->tokenFor($this->adminSucursal);

        $created = $this->withToken($token)->postJson('/api/v1/hub/product-categories', ['name' => 'Cerdo'])
            ->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/hub/product-categories/{$created}", ['name' => 'Cerdo', 'status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        // Con productos asignados → no se puede borrar.
        $this->makeProduct(['name' => 'Costilla', 'category_id' => $created]);
        $this->withToken($token)->deleteJson("/api/v1/hub/product-categories/{$created}")->assertStatus(422);
    }
}
