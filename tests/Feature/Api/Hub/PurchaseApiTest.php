<?php

namespace Tests\Feature\Api\Hub;

use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->provider = Provider::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Proveedor X', 'type' => 'insumos', 'status' => 'active',
        ]);
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    private function openShift(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'provider_id' => $this->provider->id,
            'purchased_at' => now()->toDateString(),
            'paid_amount' => 0,
            'items' => [
                ['concept' => 'Costillas', 'quantity' => 10, 'unit' => 'kg', 'unit_price' => 90],
            ],
        ], $over);
    }

    public function test_store_requires_open_shift(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/hub/purchases', $this->payload())
            ->assertStatus(409);
    }

    public function test_store_creates_purchase_with_items_and_folio(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/purchases', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.provider.name', 'Proveedor X')
            ->assertJsonPath('data.total', 900)
            ->assertJsonPath('data.amount_pending', 900);

        $this->assertSame(1, Purchase::where('created_by', $this->cajero->id)->count());
    }

    public function test_store_with_payment_reduces_pending(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/purchases', $this->payload(['paid_amount' => 400]))
            ->assertCreated()
            ->assertJsonPath('data.amount_paid', 400)
            ->assertJsonPath('data.amount_pending', 500);
    }

    public function test_index_lists_purchases_and_providers(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)->postJson('/api/v1/hub/purchases', $this->payload())->assertCreated();

        $res = $this->withToken($token)->getJson('/api/v1/hub/purchases')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Proveedor X', $res->json('providers.0.name'));
    }

    public function test_admin_empresa_forbidden(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/purchases')
            ->assertStatus(403);
    }
}
