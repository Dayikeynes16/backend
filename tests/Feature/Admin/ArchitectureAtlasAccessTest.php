<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArchitectureAtlasAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin-empresa', 'admin-sucursal', 'cajero'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/arquitectura')->assertRedirect('/login');
    }

    public function test_superadmin_can_open_the_atlas(): void
    {
        $this->actingAs($this->userWithRole('superadmin'))
            ->get('/admin/arquitectura')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ArchitectureAtlas/Index')
                ->where('manifest.metadata.schemaVersion', '1.0.0')
                ->where('manifest.metadata.snapshotRefs', fn ($snapshotRefs) => $snapshotRefs['repo.saas'] === '098daec985d4baac0627da9e3552bf498c26f76a')
                ->has('manifest.applications', 4)
                ->has('manifest.modules', 47)
                ->has('manifest.risks', 10)
                ->missing('tenants')
                ->missing('architecture'));
    }

    public function test_tenant_roles_are_forbidden(): void
    {
        foreach (['admin-empresa', 'admin-sucursal', 'cajero'] as $role) {
            $response = $this->actingAs($this->userWithRole($role))
                ->get('/admin/arquitectura')
                ->assertForbidden();

            $this->assertStringNotContainsString('risk.audit.', $response->getContent());
        }
    }
}
