<?php

namespace Tests\Feature\Api\Hub;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ConfigApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function adminToken(): string
    {
        return $this->adminSucursal->createToken('hub')->plainTextToken;
    }

    public function test_index_returns_payment_methods_and_keys(): void
    {
        $this->branch->forceFill(['payment_methods_enabled' => ['cash', 'card']])->save();

        $this->withToken($this->adminToken())
            ->getJson('/api/v1/hub/config')
            ->assertOk()
            ->assertJsonPath('payment_methods_enabled', ['cash', 'card'])
            ->assertJsonPath('branch.name', $this->branch->name);
    }

    public function test_index_includes_branch_snapshot(): void
    {
        $res = $this->withToken($this->adminToken())
            ->getJson('/api/v1/hub/config')
            ->assertOk();

        $this->assertSame('Sucursal 1', $res->json('branch_snapshot.name'));
        $this->assertArrayHasKey('schedule_text', $res->json('branch_snapshot'));
    }

    public function test_update_payment_methods(): void
    {
        $this->withToken($this->adminToken())
            ->putJson('/api/v1/hub/config/payment-methods', ['payment_methods' => ['transfer', 'cash']])
            ->assertOk()
            // Se normaliza al orden soportado (cash, card, transfer).
            ->assertJsonPath('payment_methods_enabled', ['cash', 'transfer']);

        $this->assertSame(['cash', 'transfer'], $this->branch->fresh()->payment_methods_enabled);
    }

    public function test_update_payment_methods_requires_at_least_one(): void
    {
        $this->withToken($this->adminToken())
            ->putJson('/api/v1/hub/config/payment-methods', ['payment_methods' => []])
            ->assertStatus(422);
    }

    public function test_create_revoke_and_delete_api_key(): void
    {
        $token = $this->adminToken();

        $res = $this->withToken($token)
            ->postJson('/api/v1/hub/config/api-keys', ['name' => 'Báscula 1'])
            ->assertCreated()
            ->assertJsonPath('api_keys.0.name', 'Báscula 1');
        $this->assertStringStartsWith('csa_', $res->json('raw_key'));
        $id = $res->json('api_keys.0.id');

        $this->withToken($token)
            ->deleteJson("/api/v1/hub/config/api-keys/{$id}")
            ->assertOk()
            ->assertJsonPath('api_keys.0.status', 'inactive');

        $this->withToken($token)
            ->deleteJson("/api/v1/hub/config/api-keys/{$id}/force")
            ->assertOk();

        $this->assertNull(ApiKey::withoutGlobalScopes()->find($id));
    }

    public function test_cannot_delete_active_key(): void
    {
        $token = $this->adminToken();
        $id = $this->withToken($token)
            ->postJson('/api/v1/hub/config/api-keys', ['name' => 'Activa'])
            ->json('api_keys.0.id');

        $this->withToken($token)
            ->deleteJson("/api/v1/hub/config/api-keys/{$id}/force")
            ->assertStatus(422);
    }

    public function test_cajero_forbidden(): void
    {
        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/config')
            ->assertStatus(403);
    }
}
