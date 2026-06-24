<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    private function makeExpenseOn(int $userId, float $amount, Carbon $when): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->subId,
            'user_id' => $userId,
            'concept' => 'Gasto '.$amount,
            'amount' => $amount,
            'payment_method' => 'cash',
            'expense_at' => $when,
        ]);
    }

    private function makeShiftExpense(int $shiftId, float $amount, string $method = 'cash'): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shiftId,
            'expense_subcategory_id' => $this->subId,
            'user_id' => $this->cajero->id,
            'concept' => 'Turno '.$amount,
            'amount' => $amount,
            'payment_method' => $method,
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

    public function test_index_returns_exact_daily_totals_keyed_by_day(): void
    {
        $today = now();
        $yesterday = now()->subDay();

        // Hoy: 2 gastos (100 + 50.50 = 150.50). Ayer: 1 gasto (25).
        $this->makeExpenseOn($this->cajero->id, 100, $today);
        $this->makeExpenseOn($this->cajero->id, 50.50, $today);
        $this->makeExpenseOn($this->cajero->id, 25, $yesterday);

        // Gasto de otro cajero el mismo día: no debe contar en los totales del cajero actual.
        $otro = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $this->makeExpenseOn($otro->id, 999, $today);

        $todayKey = $today->format('Y-m-d');
        $yesterdayKey = $yesterday->format('Y-m-d');

        $this->actingAs($this->cajero)
            ->get(route('caja.gastos.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Gastos/Index')
                ->where("dailyTotals.{$todayKey}.total", fn ($v) => (float) $v === 150.5)
                ->where("dailyTotals.{$todayKey}.count", 2)
                ->where("dailyTotals.{$yesterdayKey}.total", fn ($v) => (float) $v === 25.0)
                ->where("dailyTotals.{$yesterdayKey}.count", 1));
    }

    public function test_index_returns_current_shift_total_matching_corte_filter(): void
    {
        $shift = $this->openShift();

        // En efectivo, ligados al turno: 40 + 60 = 100 (2 gastos).
        $this->makeShiftExpense($shift->id, 40);
        $this->makeShiftExpense($shift->id, 60);
        // Mismo turno pero NO en efectivo: no cuenta para el corte.
        $this->makeShiftExpense($shift->id, 500, 'card');
        // Gasto sin turno (fuera del corte): tampoco cuenta.
        $this->makeExpense($this->cajero->id, 'Sin turno');

        $this->actingAs($this->cajero)
            ->get(route('caja.gastos.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Gastos/Index')
                ->where('currentShift.total', fn ($v) => (float) $v === 100.0)
                ->where('currentShift.count', 2));
    }

    public function test_index_current_shift_is_null_without_open_shift(): void
    {
        $this->makeExpense($this->cajero->id, 'X');

        $this->actingAs($this->cajero)
            ->get(route('caja.gastos.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Gastos/Index')
                ->where('currentShift', null));
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
