<?php

namespace Tests\Feature\Sucursal;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ApiKeyControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeKey(array $attrs = []): ApiKey
    {
        return ApiKey::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Báscula 1',
            'key_hash' => hash('sha256', 'csa_'.uniqid()),
            'status' => 'active',
        ], $attrs));
    }

    public function test_revoke_marks_key_inactive_but_keeps_the_row(): void
    {
        $key = $this->makeKey();

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.api-keys.destroy', [$this->tenant->slug, $key->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('api_keys', ['id' => $key->id, 'status' => 'inactive']);
    }

    public function test_force_delete_removes_a_revoked_key(): void
    {
        $key = $this->makeKey(['status' => 'inactive']);

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.api-keys.force-delete', [$this->tenant->slug, $key->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('api_keys', ['id' => $key->id]);
    }

    public function test_force_delete_removes_an_expired_key(): void
    {
        $key = $this->makeKey(['status' => 'active', 'expires_at' => now()->subDay()]);

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.api-keys.force-delete', [$this->tenant->slug, $key->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('api_keys', ['id' => $key->id]);
    }

    public function test_force_delete_refuses_a_live_key(): void
    {
        $key = $this->makeKey(['status' => 'active']);

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.api-keys.force-delete', [$this->tenant->slug, $key->id]))
            ->assertRedirect();

        // Sigue ahí, intacta — primero hay que revocarla.
        $this->assertDatabaseHas('api_keys', ['id' => $key->id, 'status' => 'active']);
    }

    public function test_cannot_force_delete_a_key_from_another_branch(): void
    {
        $key = ApiKey::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'name' => 'Otra sucursal',
            'key_hash' => hash('sha256', 'csa_'.uniqid()),
            'status' => 'inactive',
        ]);

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.api-keys.force-delete', [$this->tenant->slug, $key->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('api_keys', ['id' => $key->id]);
    }
}
