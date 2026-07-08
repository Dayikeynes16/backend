<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function login(string $email, string $password = 'password'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'Hub Sucursal 1',
        ]);
    }

    public function test_cajero_can_login_and_gets_token_and_user(): void
    {
        $res = $this->login('caja@test.local');

        $res->assertOk();
        $this->assertNotEmpty($res->json('token'));
        $res->assertJsonPath('user.email', 'caja@test.local');
        $res->assertJsonPath('user.role', 'cajero');
        $res->assertJsonPath('user.branch_id', $this->branch->id);
        $res->assertJsonPath('user.branch_name', 'Sucursal 1');
        $res->assertJsonPath('user.tenant_slug', 'test-tenant');
    }

    public function test_admin_sucursal_can_login(): void
    {
        $this->login('suc@test.local')->assertOk()->assertJsonPath('user.role', 'admin-sucursal');
    }

    public function test_user_payload_includes_cashier_feature_flags(): void
    {
        $this->branch->forceFill([
            'cashier_expenses_enabled' => true,
            'cashier_purchases_enabled' => false,
        ])->save();

        $this->login('caja@test.local')
            ->assertOk()
            ->assertJsonPath('user.cashier_expenses_enabled', true)
            ->assertJsonPath('user.cashier_purchases_enabled', false);
    }

    public function test_admin_empresa_is_forbidden(): void
    {
        $this->login('admin@test.local')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Este usuario no puede usar el hub.');
    }

    public function test_superadmin_is_forbidden(): void
    {
        $this->makeUser('super@test.local', 'superadmin', null);
        $this->login('super@test.local')->assertStatus(403);
    }

    public function test_wrong_password_returns_401(): void
    {
        $this->login('caja@test.local', 'nope')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Email o contraseña incorrectos.');
    }

    public function test_force_password_change_returns_409(): void
    {
        $this->cajero->forceFill(['force_password_change' => true])->save();
        $this->login('caja@test.local')->assertStatus(409);
    }

    public function test_me_returns_user_with_valid_token(): void
    {
        $token = $this->login('caja@test.local')->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'caja@test.local');
    }

    public function test_me_without_token_is_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->login('caja@test.local')->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')->assertOk();

        // El token quedó revocado (borrado) en la base de datos.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
