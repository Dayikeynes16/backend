<?php

namespace Tests\Feature\Api\Hub;

use App\Models\CashRegisterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('hub')->plainTextToken;
    }

    public function test_current_returns_null_when_no_open_shift(): void
    {
        $this->withToken($this->tokenFor($this->cajero))
            ->getJson('/api/v1/hub/shift/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_open_creates_a_shift(): void
    {
        $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/shift/open', ['opening_amount' => 500])
            ->assertCreated()
            ->assertJsonPath('data.opening_amount', 500);

        $this->assertSame(1, CashRegisterShift::where('user_id', $this->cajero->id)->whereNull('closed_at')->count());
    }

    public function test_open_twice_returns_409(): void
    {
        $token = $this->tokenFor($this->cajero);
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertStatus(409);
    }

    public function test_close_returns_corte_totals(): void
    {
        $token = $this->tokenFor($this->cajero);
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/hub/shift/close', ['declared_amount' => 0])
            ->assertOk()
            ->assertJsonPath('data.closed', true);

        $this->assertNotNull(CashRegisterShift::where('user_id', $this->cajero->id)->latest('id')->first()->closed_at);
    }

    public function test_admin_empresa_token_is_forbidden(): void
    {
        $this->withToken($this->tokenFor($this->adminEmpresa))
            ->getJson('/api/v1/hub/shift/current')
            ->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/hub/shift/current')->assertStatus(401);
    }
}
