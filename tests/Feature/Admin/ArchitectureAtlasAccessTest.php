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
                ->missing('tenants')
                ->missing('architecture'));
    }

    public function test_tenant_roles_are_forbidden(): void
    {
        foreach (['admin-empresa', 'admin-sucursal', 'cajero'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/admin/arquitectura')
                ->assertForbidden();
        }
    }
}
