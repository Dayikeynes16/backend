<?php

namespace Tests\Feature\Api\Hub;

use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProviderApiTest extends TestCase
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

    private function enableToggle(): void
    {
        $this->branch->forceFill(['branch_admin_providers_enabled' => true])->save();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Proveedor Test',
            'type' => 'insumos',
            'phone' => '555',
        ], $overrides);
    }

    private function makeProvider(array $attrs = []): Provider
    {
        return Provider::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Carnes ABC',
            'type' => 'mayorista_carne',
            'status' => 'active',
        ], $attrs));
    }

    public function test_index_lists_providers_for_admin_sucursal(): void
    {
        $this->makeProvider();

        $res = $this->withToken($this->adminToken())
            ->getJson('/api/v1/hub/providers')
            ->assertOk();

        $this->assertSame('Carnes ABC', $res->json('data.0.name'));
        $this->assertSame('Mayorista de carne', $res->json('data.0.type_label'));
        $this->assertFalse($res->json('can_manage')); // toggle apagado por default
    }

    public function test_index_reports_can_manage_when_toggle_on(): void
    {
        $this->enableToggle();

        $res = $this->withToken($this->adminToken())
            ->getJson('/api/v1/hub/providers')
            ->assertOk();

        $this->assertTrue($res->json('can_manage'));
    }

    public function test_cajero_forbidden(): void
    {
        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/providers')
            ->assertStatus(403);
    }

    public function test_admin_empresa_forbidden_by_hub_role(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/providers')
            ->assertStatus(403);
    }

    public function test_store_forbidden_when_toggle_off(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/providers', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, Provider::withoutGlobalScopes()->count());
    }

    public function test_store_creates_provider_when_toggle_on(): void
    {
        $this->enableToggle();

        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/providers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Proveedor Test')
            ->assertJsonPath('data.type_label', 'Insumos')
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, Provider::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $this->enableToggle();
        $this->makeProvider(['name' => 'Repetido']);

        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/providers', $this->payload(['name' => 'Repetido']))
            ->assertStatus(422);
    }

    public function test_update_edits_provider_when_toggle_on(): void
    {
        $this->enableToggle();
        $provider = $this->makeProvider(['name' => 'Viejo']);

        $this->withToken($this->adminToken())
            ->putJson("/api/v1/hub/providers/{$provider->id}", $this->payload([
                'name' => 'Nuevo', 'status' => 'inactive',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuevo')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_update_forbidden_when_toggle_off(): void
    {
        $provider = $this->makeProvider();

        $this->withToken($this->adminToken())
            ->putJson("/api/v1/hub/providers/{$provider->id}", $this->payload(['status' => 'active']))
            ->assertStatus(403);
    }

    public function test_no_destroy_route(): void
    {
        $this->enableToggle();
        $provider = $this->makeProvider();

        // No existe ruta DELETE para proveedores en el hub (el borrado queda en empresa).
        $this->withToken($this->adminToken())
            ->deleteJson("/api/v1/hub/providers/{$provider->id}")
            ->assertStatus(405);
    }

    public function test_cross_tenant_update_returns_404(): void
    {
        $this->enableToggle();

        $otherTenant = Tenant::create(['name' => 'Otro', 'slug' => 'otro-tenant', 'status' => 'active']);
        app()->instance('tenant', $otherTenant);
        $foreign = Provider::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Ajeno', 'type' => 'otro', 'status' => 'active',
        ]);

        $this->withToken($this->adminToken())
            ->putJson("/api/v1/hub/providers/{$foreign->id}", $this->payload(['status' => 'active']))
            ->assertStatus(404);
    }
}
