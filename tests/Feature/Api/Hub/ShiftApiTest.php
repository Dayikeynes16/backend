<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use App\Models\Payment;
use App\Models\Sale;
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
        app()->instance('tenant', $this->tenant);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('hub')->plainTextToken;
    }

    private function openShiftWithMovement(float $opening = 500): CashRegisterShift
    {
        $shift = CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => $opening,
        ]);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => 500,
            'amount_paid' => 500,
            'amount_pending' => 0,
            'origin' => 'api',
            'status' => SaleStatus::Active,
        ]);

        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 300]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'card', 'amount' => 200]);

        CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $this->cajero->id,
            'amount' => 50,
            'reason' => 'Cambio',
            'created_at' => now(),
        ]);

        return $shift;
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

    public function test_current_includes_live_summary_for_open_shift(): void
    {
        $this->openShiftWithMovement(500);

        $res = $this->withToken($this->tokenFor($this->cajero))
            ->getJson('/api/v1/hub/shift/current')
            ->assertOk()
            ->assertJsonPath('summary.is_open', true)
            ->assertJsonPath('summary.totals.cash', 300)
            ->assertJsonPath('summary.totals.card', 200)
            // 500 fondo + 300 efectivo cobrado − 50 retiro = 750 esperado.
            ->assertJsonPath('summary.expected_cash', 750)
            ->assertJsonPath('summary.cash_out.withdrawals', 50);

        // Mientras está abierto, declarado/diferencia van en null.
        $cashRow = collect($res->json('summary.reconciliation'))->firstWhere('method', 'cash');
        $this->assertEquals(750, $cashRow['expected']);
        $this->assertNull($cashRow['declared']);
        $this->assertCount(1, $res->json('summary.breakdown.withdrawals'));
    }

    public function test_close_with_multi_method_declared_reports_differences(): void
    {
        $this->openShiftWithMovement(500);

        $res = $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/shift/close', [
                'declared_amount' => 740,   // faltan 10 en efectivo (esperado 750)
                'declared_card' => 200,     // cuadra tarjeta
                'notes' => 'Faltante de 10',
            ])
            ->assertOk()
            ->assertJsonPath('data.closed', true)
            ->assertJsonPath('summary.is_open', false)
            ->assertJsonPath('summary.difference_total', -10)
            ->assertJsonPath('data.notes', 'Faltante de 10');

        $rows = collect($res->json('summary.reconciliation'))->keyBy('method');
        $this->assertEquals(-10, $rows['cash']['difference']);
        $this->assertEquals(0, $rows['card']['difference']);
    }
}
