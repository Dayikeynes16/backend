<?php

namespace Tests\Feature\Api\Hub;

use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class WithdrawalApiTest extends TestCase
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

    private function openShiftFor(User $user): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 1000,
        ]);
    }

    private function makeWithdrawal(CashRegisterShift $shift, int $userId, float $amount = 100): CashWithdrawal
    {
        return CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $userId,
            'amount' => $amount,
            'reason' => 'Cambio',
            'created_at' => now(),
        ]);
    }

    public function test_cajero_registers_withdrawal_and_gets_fresh_summary(): void
    {
        $this->openShiftFor($this->cajero);

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/shift/withdrawals', [
                'amount' => 250.50,
                'reason' => 'Compra de bolsas',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 250.5)
            ->assertJsonPath('data.reason', 'Compra de bolsas')
            ->assertJsonPath('summary.cash_out.withdrawals', 250.5)
            // 1000 de fondo − 250.50 de retiro, sin cobros.
            ->assertJsonPath('summary.expected_cash', 749.5);

        $this->assertDatabaseHas('cash_withdrawals', [
            'user_id' => $this->cajero->id,
            'amount' => 250.50,
            'reason' => 'Compra de bolsas',
        ]);
    }

    public function test_store_validates_amount_and_reason(): void
    {
        $this->openShiftFor($this->cajero);

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/shift/withdrawals', ['amount' => 0, 'reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'reason']);
    }

    public function test_store_fails_without_open_shift(): void
    {
        $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/shift/withdrawals', ['amount' => 100, 'reason' => 'Cambio'])
            ->assertNotFound();
    }

    public function test_cajero_deletes_own_withdrawal_on_open_shift(): void
    {
        $shift = $this->openShiftFor($this->cajero);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->withToken($this->tokenFor($this->cajero))
            ->deleteJson("/api/v1/hub/shift/withdrawals/{$w->id}")
            ->assertOk()
            ->assertJsonPath('summary.cash_out.withdrawals', 0);

        $this->assertDatabaseMissing('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_cajero_cannot_delete_withdrawal_on_closed_shift(): void
    {
        $shift = $this->openShiftFor($this->cajero);
        $shift->update(['closed_at' => now()]);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->withToken($this->tokenFor($this->cajero))
            ->deleteJson("/api/v1/hub/shift/withdrawals/{$w->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_cajero_cannot_delete_another_users_withdrawal(): void
    {
        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $otherShift = $this->openShiftFor($otherCajero);
        $w = $this->makeWithdrawal($otherShift, $otherCajero->id);

        $this->withToken($this->tokenFor($this->cajero))
            ->deleteJson("/api/v1/hub/shift/withdrawals/{$w->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_admin_sucursal_deletes_withdrawal_even_on_closed_shift(): void
    {
        $shift = $this->openShiftFor($this->cajero);
        // Corte cerrado con un retiro de 200 ya descontado del esperado.
        $shift->update([
            'closed_at' => now(),
            'total_cash' => 800,
            'expected_amount' => 800, // 1000 fondo + 0 cobros − 200 retiro
            'difference' => 0,
            'declared_amount' => 800,
        ]);
        $w = $this->makeWithdrawal($shift, $this->cajero->id, 200);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/shift/withdrawals/{$w->id}")
            ->assertOk();

        $this->assertDatabaseMissing('cash_withdrawals', ['id' => $w->id]);
        // El corte cerrado se recalcula: sin el retiro, el esperado sube a 1000.
        $this->assertEquals(1000, $shift->refresh()->expected_amount);
        // La respuesta trae el resumen del turno AFECTADO (no el del admin).
        $this->assertEquals(0, $res->json('summary.cash_out.withdrawals'));
    }

    public function test_cannot_delete_withdrawal_from_another_branch(): void
    {
        $otherCajero = $this->makeUser('caja-suc2@test.local', 'cajero', $this->secondBranch->id);
        $otherShift = $this->openShiftFor($otherCajero);
        $w = $this->makeWithdrawal($otherShift, $otherCajero->id);

        // Ni siquiera el admin-sucursal de la sucursal 1 puede tocar retiros de la 2.
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/shift/withdrawals/{$w->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('cash_withdrawals', ['id' => $w->id]);
    }
}
