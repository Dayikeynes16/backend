<?php

namespace Tests\Feature\Api\Hub;

use App\Models\PurchaseProduct;
use App\Models\PurchaseProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseProductManageApiTest extends TestCase
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

    private function category(string $name = 'Cárnicos'): PurchaseProductCategory
    {
        return PurchaseProductCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    public function test_cajero_cannot_manage_catalog(): void
    {
        $token = $this->tokenFor($this->cajero);

        $this->withToken($token)->getJson('/api/v1/hub/purchase-products/manage')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/hub/purchase-products', ['name' => 'X', 'unit' => 'kg'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/hub/purchase-product-categories', ['name' => 'Y'])->assertForbidden();
    }

    public function test_admin_lists_manage_data_with_categories_and_stats(): void
    {
        $cat = $this->category();
        PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Costilla', 'unit' => 'kg', 'purchase_product_category_id' => $cat->id, 'status' => 'active']);
        PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Bolsas', 'unit' => 'pieza', 'status' => 'inactive']);

        // stats es del catálogo completo; la lista respeta el filtro de estado
        // (default 'active'). Con status=all se ven ambos.
        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson('/api/v1/hub/purchase-products/manage?status=all')
            ->assertOk();

        $this->assertSame(2, $res->json('stats.total'));
        $this->assertSame(1, $res->json('stats.active'));
        $this->assertSame(1, $res->json('stats.uncategorized'));
        $this->assertNotEmpty($res->json('categoryRows'));
        $this->assertCount(2, $res->json('products.data'));

        // Por default solo los activos.
        $active = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson('/api/v1/hub/purchase-products/manage')->assertOk();
        $this->assertCount(1, $active->json('products.data'));
    }

    public function test_admin_creates_and_updates_product(): void
    {
        $cat = $this->category();

        $created = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/purchase-products', [
                'name' => 'Pierna de cerdo', 'unit' => 'kg', 'purchase_product_category_id' => $cat->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pierna de cerdo')
            ->assertJsonPath('data.category_label', 'Cárnicos');

        $id = $created->json('data.id');

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/purchase-products/{$id}", [
                'name' => 'Pierna de cerdo', 'unit' => 'kg', 'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('purchase_products', ['id' => $id, 'status' => 'inactive']);
    }

    public function test_product_name_is_unique_per_tenant(): void
    {
        PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Repetido', 'unit' => 'kg', 'status' => 'active']);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/purchase-products', ['name' => 'Repetido', 'unit' => 'kg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_creates_and_updates_category(): void
    {
        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/purchase-product-categories', ['name' => 'Insumos'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Insumos');

        $id = $res->json('data.id');

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/purchase-product-categories/{$id}", ['name' => 'Insumos', 'status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_history_records_changes(): void
    {
        $product = PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Rastro', 'unit' => 'kg', 'status' => 'active']);

        // Genera un cambio auditado.
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/purchase-products/{$product->id}", ['name' => 'Rastro Central', 'unit' => 'kg', 'status' => 'active'])
            ->assertOk();

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson("/api/v1/hub/purchase-products/{$product->id}/history")
            ->assertOk();

        $this->assertSame('Rastro Central', $res->json('product.name'));
        $this->assertNotEmpty($res->json('history'));
    }
}
