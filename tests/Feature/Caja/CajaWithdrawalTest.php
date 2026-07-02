<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
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
}
