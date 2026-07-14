<?php

namespace Tests\Feature\Api\Hub;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class UserManageApiTest extends TestCase
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

    public function test_cajero_cannot_manage_users(): void
    {
        $token = $this->tokenFor($this->cajero);

        $this->withToken($token)->getJson('/api/v1/hub/users')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/hub/users', [
            'name' => 'X', 'email' => 'x@test.local', 'password' => 'password123',
        ])->assertForbidden();
    }

    public function test_admin_lists_only_cajeros_of_own_branch(): void
    {
        // Otro cajero en la misma sucursal + un cajero en otra sucursal.
        $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $this->makeUser('caja-s2@test.local', 'cajero', $this->secondBranch->id);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson('/api/v1/hub/users')
            ->assertOk();

        $emails = collect($res->json('data'))->pluck('email');
        $this->assertTrue($emails->contains('caja@test.local'));   // el cajero seed
        $this->assertTrue($emails->contains('caja2@test.local'));
        $this->assertFalse($emails->contains('caja-s2@test.local')); // otra sucursal
        // El propio admin no aparece (no es cajero).
        $this->assertFalse($emails->contains('suc@test.local'));
        $this->assertArrayHasKey('max_users', $res->json('limits'));
    }

    public function test_admin_creates_cajero_with_role_and_branch(): void
    {
        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/users', [
                'name' => 'Nuevo Cajero', 'email' => 'nuevo@test.local', 'password' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nuevo Cajero');

        $created = User::where('email', 'nuevo@test.local')->first();
        $this->assertTrue($created->hasRole('cajero'));
        $this->assertSame($this->branch->id, $created->branch_id);
        $this->assertSame($this->tenant->id, $created->tenant_id);
    }

    public function test_email_must_be_unique_globally(): void
    {
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/users', [
                'name' => 'Repetido', 'email' => 'caja@test.local', 'password' => 'password123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_store_respects_max_users_limit(): void
    {
        $this->tenant->update(['max_users' => $this->tenant->users()->count()]);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson('/api/v1/hub/users', [
                'name' => 'Sobra', 'email' => 'sobra@test.local', 'password' => 'password123',
            ])
            ->assertStatus(422);
    }

    public function test_update_changes_name_and_optional_password(): void
    {
        $cajero = $this->makeUser('editable@test.local', 'cajero', $this->branch->id);
        $oldHash = $cajero->password;

        // Sin password: no cambia el hash.
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/users/{$cajero->id}", [
                'name' => 'Editado', 'email' => 'editable@test.local',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Editado');
        $this->assertSame($oldHash, $cajero->refresh()->password);

        // Con password: cambia el hash.
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/users/{$cajero->id}", [
                'name' => 'Editado', 'email' => 'editable@test.local', 'password' => 'nuevaclave123',
            ])
            ->assertOk();
        $this->assertNotSame($oldHash, $cajero->refresh()->password);
    }

    public function test_cannot_manage_admin_or_other_branch_user(): void
    {
        // No puede editar a otro admin (no es cajero) ni a un cajero de otra sucursal.
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/users/{$this->adminSucursal->id}", [
                'name' => 'Yo', 'email' => 'suc@test.local',
            ])
            ->assertForbidden();

        $otherCajero = $this->makeUser('caja-otra@test.local', 'cajero', $this->secondBranch->id);
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/users/{$otherCajero->id}")
            ->assertNotFound();
    }

    public function test_admin_deletes_cajero(): void
    {
        $cajero = $this->makeUser('borrar@test.local', 'cajero', $this->branch->id);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/users/{$cajero->id}")
            ->assertOk()
            ->assertJsonPath('action', 'deleted');

        $this->assertDatabaseMissing('users', ['id' => $cajero->id]);
    }
}
