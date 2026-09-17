<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión propia del middleware AuthenticateApiKey: la sucursal que
 * resuelve debe quedar fija al tenant de LA KEY que se usó, sin importar
 * qué `app('tenant')` haya quedado vinculado de una request anterior en el
 * mismo proceso (tests, Octane, colas).
 */
class AuthenticateApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_keys_of_different_tenants_resolve_their_own_tenant_in_the_same_process(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $branchA = Branch::create(['tenant_id' => $tenantA->id, 'name' => 'Sucursal A', 'address' => 'Calle A', 'status' => 'active']);
        $rawKeyA = 'csa_test_'.str_repeat('a', 20);
        ApiKey::create(['tenant_id' => $tenantA->id, 'branch_id' => $branchA->id, 'name' => 'kA', 'key_hash' => hash('sha256', $rawKeyA)]);

        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
        $branchB = Branch::create(['tenant_id' => $tenantB->id, 'name' => 'Sucursal B', 'address' => 'Calle B', 'status' => 'active']);
        $rawKeyB = 'csa_test_'.str_repeat('b', 20);
        ApiKey::create(['tenant_id' => $tenantB->id, 'branch_id' => $branchB->id, 'name' => 'kB', 'key_hash' => hash('sha256', $rawKeyB)]);

        $this->withHeader('X-Api-Key', $rawKeyA)
            ->getJson('/api/v1/branches/me')
            ->assertOk()
            ->assertJsonPath('data.id', $branchA->id)
            ->assertJsonPath('data.name', 'Sucursal A');

        $this->withHeader('X-Api-Key', $rawKeyB)
            ->getJson('/api/v1/branches/me')
            ->assertOk()
            ->assertJsonPath('data.id', $branchB->id)
            ->assertJsonPath('data.name', 'Sucursal B');
    }

    public function test_key_whose_branch_belongs_to_another_tenant_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $otherTenant = Tenant::create(['name' => 'O', 'slug' => 'o', 'status' => 'active']);
        $foreignBranch = Branch::create(['tenant_id' => $otherTenant->id, 'name' => 'Ajena', 'address' => 'Calle C', 'status' => 'active']);

        $rawKey = 'csa_test_'.str_repeat('c', 20);
        ApiKey::create(['tenant_id' => $tenant->id, 'branch_id' => $foreignBranch->id, 'name' => 'k', 'key_hash' => hash('sha256', $rawKey)]);

        $this->withHeader('X-Api-Key', $rawKey)
            ->getJson('/api/v1/branches/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'API Key inválida o inactiva.');
    }
}
