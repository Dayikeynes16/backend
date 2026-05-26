<?php

namespace Tests\Feature\Sucursal;

use App\Models\CashRegisterShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CashShiftCloseTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function openShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 1000,
        ]);
    }

    public function test_close_treats_blank_declared_amounts_as_zero(): void
    {
        $shift = $this->openShift(); // fondo 1000, sin cobros

        $this->actingAs($this->adminSucursal);
        // No se captura ningún declarado: el cierre no debe bloquearse ni fallar.
        $this->post(route('sucursal.turno.close', $this->tenant->slug), [])
            ->assertRedirect();

        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        // Métodos efectivos → declarado se concilia como 0.00 (no NULL).
        $this->assertSame('0.00', $shift->declared_amount);
        $this->assertSame('0.00', $shift->declared_card);
        $this->assertSame('0.00', $shift->declared_transfer);
        // esperado = 1000 fondo; declarado 0 → faltante de 1000.
        $this->assertSame('1000.00', $shift->expected_amount);
        $this->assertSame('-1000.00', $shift->difference);
    }

    public function test_close_accepts_explicit_zero(): void
    {
        $shift = $this->openShift();

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.turno.close', $this->tenant->slug), [
            'declared_amount' => 1000,
            'declared_card' => 0,
            'declared_transfer' => 0,
        ])->assertRedirect();

        $shift->refresh();
        // Declarado 1000 == esperado 1000 → cuadra.
        $this->assertSame('1000.00', $shift->declared_amount);
        $this->assertSame('0.00', $shift->difference);
    }
}
