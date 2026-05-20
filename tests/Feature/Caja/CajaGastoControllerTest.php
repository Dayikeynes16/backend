<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaGastoControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private int $subId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Op', 'status' => 'active']);
        $this->subId = ExpenseSubcategory::create([
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
            'opened_at' => now(),
            'opening_amount' => 1000,
        ]);
    }

    public function test_cajero_registers_cash_expense_tied_to_open_shift(): void
    {
        $shift = $this->openShift();

        $this->actingAs($this->cajero);
        $this->post(route('caja.gastos.store', $this->tenant->slug), [
            'concept' => 'Bolsas',
            'amount' => 120.50,
            'expense_subcategory_id' => $this->subId,
        ])->assertRedirect();

        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'user_id' => $this->cajero->id,
            'concept' => 'Bolsas',
            'payment_method' => 'cash',
        ]);
    }

    public function test_requires_open_shift(): void
    {
        $this->actingAs($this->cajero);
        $this->post(route('caja.gastos.store', $this->tenant->slug), [
            'concept' => 'Bolsas',
            'amount' => 50,
            'expense_subcategory_id' => $this->subId,
        ])->assertStatus(422);

        $this->assertSame(0, Expense::count());
    }

    public function test_rejects_subcategory_from_other_tenant(): void
    {
        $this->openShift();

        $this->actingAs($this->cajero);
        $this->from(route('caja.turno', $this->tenant->slug))
            ->post(route('caja.gastos.store', $this->tenant->slug), [
                'concept' => 'X',
                'amount' => 10,
                'expense_subcategory_id' => 999999,
            ])->assertSessionHasErrors('expense_subcategory_id');
    }
}
