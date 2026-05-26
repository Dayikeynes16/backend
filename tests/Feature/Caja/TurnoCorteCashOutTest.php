<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Services\RecalculateClosedShifts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class TurnoCorteCashOutTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function subId(): int
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Op', 'status' => 'active']);

        return ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Insumos',
            'status' => 'active',
        ])->id;
    }

    private function openShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 1000,
        ]);
    }

    private function cashExpense(CashRegisterShift $shift, float $amount): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $this->subId(),
            'user_id' => $this->cajero->id,
            'concept' => 'Bolsas',
            'amount' => $amount,
            'payment_method' => 'cash',
            'expense_at' => now(),
        ]);
    }

    public function test_close_subtracts_cash_expenses_from_expected(): void
    {
        $shift = $this->openShift();
        $this->cashExpense($shift, 250);

        $this->actingAs($this->cajero);
        $this->post(route('caja.turno.close', $this->tenant->slug), [
            'declared_amount' => 750,
            'declared_card' => 0,
            'declared_transfer' => 0,
        ])->assertRedirect();

        $shift->refresh();
        // esperado = 1000 fondo + 0 cobrado - 0 retiros - 250 gastos = 750
        $this->assertSame('750.00', $shift->expected_amount);
        $this->assertSame('250.00', $shift->total_cash_expenses);
        // declarado 750 == esperado 750 → diferencia 0
        $this->assertSame('0.00', $shift->difference);
    }

    public function test_close_subtracts_cash_provider_payments_from_expected(): void
    {
        $shift = $this->openShift();

        ProviderPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'provider_id' => Provider::create(['name' => 'Prov', 'type' => 'otro'])->id,
            'paid_at' => now(),
            'amount' => 300,
            'payment_method' => 'cash',
            'user_id' => $this->cajero->id,
        ]);

        $this->actingAs($this->cajero);
        $this->post(route('caja.turno.close', $this->tenant->slug), [
            'declared_amount' => 700,
            'declared_card' => 0,
            'declared_transfer' => 0,
        ])->assertRedirect();

        $shift->refresh();
        // esperado = 1000 - 0 retiros - 0 gastos - 300 pagos a proveedor = 700
        $this->assertSame('700.00', $shift->expected_amount);
        $this->assertSame('300.00', $shift->total_cash_provider_payments);
    }

    public function test_close_treats_blank_declared_amounts_as_zero(): void
    {
        $shift = $this->openShift(); // fondo 1000, sin cobros en el turno

        $this->actingAs($this->cajero);
        // El cajero no captura nada: los campos de declarado llegan ausentes/vacíos.
        $this->post(route('caja.turno.close', $this->tenant->slug), [])
            ->assertRedirect();

        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        // Métodos efectivos (cash/card/transfer habilitados) → declarado = 0.00, no NULL.
        $this->assertSame('0.00', $shift->declared_amount);
        $this->assertSame('0.00', $shift->declared_card);
        $this->assertSame('0.00', $shift->declared_transfer);
        // esperado = 1000 fondo; declarado 0 → faltante de 1000 en efectivo.
        $this->assertSame('1000.00', $shift->expected_amount);
        $this->assertSame('-1000.00', $shift->difference);
        $this->assertSame('0.00', $shift->difference_card);
        $this->assertSame('0.00', $shift->difference_transfer);
    }

    public function test_close_treats_empty_string_declared_as_zero(): void
    {
        $shift = $this->openShift();

        $this->actingAs($this->cajero);
        // Strings vacíos (lo que envía el front si no se completa) no deben romper el cierre.
        $this->post(route('caja.turno.close', $this->tenant->slug), [
            'declared_amount' => '',
            'declared_card' => '',
            'declared_transfer' => '',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        $this->assertSame('0.00', $shift->declared_amount);
        $this->assertSame('1000.00', $shift->expected_amount);
        $this->assertSame('-1000.00', $shift->difference);
    }

    public function test_recalculate_after_soft_deleting_expense(): void
    {
        $shift = $this->openShift();
        $expense = $this->cashExpense($shift, 250);

        $this->actingAs($this->cajero);
        $this->post(route('caja.turno.close', $this->tenant->slug), [
            'declared_amount' => 750,
            'declared_card' => 0,
            'declared_transfer' => 0,
        ])->assertRedirect();

        // Se cancela el gasto; recálculo manual del turno cerrado.
        $expense->delete();
        app(RecalculateClosedShifts::class)->forShift($shift->refresh());

        $shift->refresh();
        // ahora esperado = 1000 (sin gastos)
        $this->assertSame('1000.00', $shift->expected_amount);
        $this->assertSame('0.00', $shift->total_cash_expenses);
    }
}
