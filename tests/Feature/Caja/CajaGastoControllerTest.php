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

    private function makeExpense(int $userId, string $concept): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->subId,
            'user_id' => $userId,
            'concept' => $concept,
            'amount' => 10,
            'payment_method' => 'cash',
            'expense_at' => now(),
        ]);
    }

    public function test_index_shows_only_the_cajeros_own_expenses(): void
    {
        $this->makeExpense($this->cajero->id, 'Mío');

        $otroCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $this->makeExpense($otroCajero->id, 'Ajeno');

        $this->actingAs($this->cajero)
            ->get(route('caja.gastos.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Gastos/Index')
                ->has('expenses.data', 1)
                ->where('expenses.data.0.concept', 'Mío'));
    }

    public function test_index_forbidden_when_expenses_disabled_for_branch(): void
    {
        $this->branch->update(['cashier_expenses_enabled' => false]);

        $this->actingAs($this->cajero)
            ->get(route('caja.gastos.index', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_store_forbidden_when_expenses_disabled_for_branch(): void
    {
        $this->branch->update(['cashier_expenses_enabled' => false]);
        $this->openShift();

        $this->actingAs($this->cajero)
            ->post(route('caja.gastos.store', $this->tenant->slug), [
                'concept' => 'Bolsas',
                'amount' => 50,
                'expense_subcategory_id' => $this->subId,
            ])->assertForbidden();

        $this->assertSame(0, Expense::count());
    }
}
