<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaWithdrawalTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function openShiftFor(int $userId): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $userId,
            'opened_at' => now()->subHour(),
            'opening_amount' => 1000,
        ]);
    }

    public function test_cajero_can_register_withdrawal_on_open_shift(): void
    {
        $this->openShiftFor($this->cajero->id);

        $this->actingAs($this->cajero)
            ->post(route('caja.turno.withdrawal.store', $this->tenant->slug), [
                'amount' => 250.50,
                'reason' => 'Compra de bolsas',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cash_withdrawals', [
            'user_id' => $this->cajero->id,
            'amount' => 250.50,
            'reason' => 'Compra de bolsas',
        ]);
    }

    private function makeWithdrawal(CashRegisterShift $shift, int $userId): CashWithdrawal
    {
        return CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $userId,
            'amount' => 100,
            'reason' => 'Cambio',
            'created_at' => now(),
        ]);
    }

    public function test_cajero_can_delete_own_withdrawal_on_open_shift(): void
    {
        $shift = $this->openShiftFor($this->cajero->id);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->actingAs($this->cajero)
            ->delete(route('caja.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_cajero_cannot_delete_withdrawal_on_closed_shift(): void
    {
        $shift = $this->openShiftFor($this->cajero->id);
        $shift->update(['closed_at' => now()]);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->actingAs($this->cajero)
            ->delete(route('caja.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_cajero_cannot_delete_another_users_withdrawal(): void
    {
        // Otro cajero de la MISMA sucursal, con su propio turno abierto.
        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $otherShift = $this->openShiftFor($otherCajero->id);
        $w = $this->makeWithdrawal($otherShift, $otherCajero->id);

        $this->actingAs($this->cajero)
            ->delete(route('caja.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_admin_sucursal_can_still_delete_withdrawal_on_closed_shift(): void
    {
        // Regresión: el admin conserva su permiso incluso con turno cerrado.
        $shift = $this->openShiftFor($this->cajero->id);
        $shift->update(['closed_at' => now()]);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('cash_withdrawals', ['id' => $w->id]);
    }
}
