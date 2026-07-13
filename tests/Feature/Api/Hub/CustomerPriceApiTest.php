<?php

namespace Tests\Feature\Api\Hub;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerPriceApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente',
            'phone' => '6610000001',
            'status' => 'active',
        ]);
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    /** Los precios preferenciales son de admin-sucursal (paridad web). */
    private function adminToken(): string
    {
        return $this->adminSucursal->createToken('hub')->plainTextToken;
    }

    private function product(string $name = 'Bistec', float $price = 120): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'price' => $price,
            'unit_type' => 'kg',
            'status' => 'active',
        ]);
    }

    public function test_products_endpoint_searches_active_branch_products(): void
    {
        $this->product('Bistec de res');
        $this->product('Chuleta');

        $res = $this->withToken($this->token())
            ->getJson('/api/v1/hub/products?search=res')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Bistec de res', $res->json('data.0.name'));
    }

    public function test_cajero_cannot_manage_preferential_prices(): void
    {
        $product = $this->product();

        $this->withToken($this->token())
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/prices", [
                'product_id' => $product->id, 'price' => 99,
            ])
            ->assertForbidden();
    }

    public function test_store_and_show_preferential_price(): void
    {
        $product = $this->product();

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/prices", [
                'product_id' => $product->id, 'price' => 99,
            ])
            ->assertCreated()
            ->assertJsonPath('data.price', 99)
            ->assertJsonPath('data.product_name', 'Bistec');

        // Mismo usuario (el guard de Sanctum cachea al usuario en el test).
        $this->withToken($this->adminToken())
            ->getJson("/api/v1/hub/customers/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('prices.0.price', 99);
    }

    public function test_rejects_duplicate_product_price(): void
    {
        $product = $this->product();
        $this->withToken($this->adminToken())
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/prices", ['product_id' => $product->id, 'price' => 99])
            ->assertCreated();

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/prices", ['product_id' => $product->id, 'price' => 80])
            ->assertStatus(422);
    }

    public function test_update_and_destroy_price(): void
    {
        $product = $this->product();
        $priceId = $this->withToken($this->adminToken())
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/prices", ['product_id' => $product->id, 'price' => 99])
            ->json('data.id');

        $this->withToken($this->adminToken())
            ->patchJson("/api/v1/hub/customers/{$this->customer->id}/prices/{$priceId}", ['price' => 85])
            ->assertOk()
            ->assertJsonPath('data.price', 85);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/v1/hub/customers/{$this->customer->id}/prices/{$priceId}")
            ->assertOk()
            ->assertJsonPath('action', 'deleted');
    }
}
