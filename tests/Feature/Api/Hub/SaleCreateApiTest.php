<?php

namespace Tests\Feature\Api\Hub;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleCreateApiTest extends TestCase
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

    public function test_cajero_cannot_create_sale(): void
    {
        $product = $this->makeProduct(['name' => 'Bistec', 'price' => 100]);

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_admin_creates_sale_with_catalog_price(): void
    {
        $beef = $this->makeProduct(['name' => 'Arrachera', 'price' => 250]);
        $pork = $this->makeProduct(['name' => 'Chuleta', 'price' => 120]);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/sales', [
                'items' => [
                    ['product_id' => $beef->id, 'quantity' => 2],   // 500
                    ['product_id' => $pork->id, 'quantity' => 1],   // 120
                ],
            ])
            ->assertCreated();

        $this->assertEquals(620, $res->json('data.total'));
        $this->assertEquals(620, $res->json('data.amount_pending'));
        $this->assertSame('active', $res->json('data.status'));
        $this->assertSame('admin', $res->json('data.origin'));
        $this->assertCount(2, $res->json('data.items'));
        $this->assertMatchesRegularExpression('/^S-\d{5}$/', $res->json('data.folio'));
    }

    public function test_admin_can_override_price(): void
    {
        $product = $this->makeProduct(['name' => 'Especial', 'price' => 200]);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'custom_price' => 150]],
            ])
            ->assertCreated();

        $this->assertEquals(150, $res->json('data.total'));
        $this->assertEquals(150, $res->json('data.items.0.unit_price'));
    }

    public function test_rejects_product_of_other_branch_or_inactive(): void
    {
        $foreign = Product::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->secondBranch->id,
            'name' => 'Ajeno', 'price' => 100, 'unit_type' => 'kg', 'status' => 'active',
        ]);
        $inactive = $this->makeProduct(['name' => 'Inactivo', 'price' => 50, 'status' => 'inactive']);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/sales', ['items' => [['product_id' => $foreign->id, 'quantity' => 1]]])
            ->assertStatus(422);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/sales', ['items' => [['product_id' => $inactive->id, 'quantity' => 1]]])
            ->assertStatus(422);
    }

    public function test_requires_at_least_one_item(): void
    {
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/sales', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }
}
