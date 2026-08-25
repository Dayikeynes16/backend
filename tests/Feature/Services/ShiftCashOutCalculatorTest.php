<?php

namespace Tests\Feature\Services;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\ShiftCashOutCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftCashOutCalculatorTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function subcategoryId(): int
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Operación', 'status' => 'active']);
        $sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Insumos',
            'status' => 'active',
        ]);

        return $sub->id;
    }

    private function makeShift(array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now(),
            'opening_amount' => 1000,
        ], $attrs));
    }

    private function makeCashExpense(CashRegisterShift $shift, float $amount, string $method = 'cash'): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $this->subcategoryId(),
            'user_id' => $this->cajero->id,
            'concept' => 'Bolsas',
            'amount' => $amount,
            'payment_method' => $method,
            'expense_at' => now(),
        ]);
    }

    public function test_sums_only_cash_expenses_of_the_shift(): void
    {
        $shift = $this->makeShift();
        $this->makeCashExpense($shift, 150);
        $this->makeCashExpense($shift, 50);
        $this->makeCashExpense($shift, 999, 'card'); // tarjeta: no cuenta

        $result = (new ShiftCashOutCalculator)->forShift($shift, totalCash: 500, totalWithdrawals: 100);

        $this->assertSame(200.0, $result['cash_expenses']);
        // esperado = 1000 fondo + 500 cobrado - 100 retiros - 200 gastos = 1200
        $this->assertSame(1200.0, $result['expected_amount']);
    }

    public function test_excludes_soft_deleted_expenses(): void
    {
        $shift = $this->makeShift();
        $expense = $this->makeCashExpense($shift, 300);
        $expense->delete();

        $result = (new ShiftCashOutCalculator)->forShift($shift, totalCash: 0, totalWithdrawals: 0);

        $this->assertSame(0.0, $result['cash_expenses']);
        $this->assertSame(1000.0, $result['expected_amount']);
    }

    public function test_ignores_expenses_of_other_shifts(): void
    {
        // El otro turno es el anterior del mismo cajero, ya cerrado: la base no
        // admite dos turnos abiertos del mismo usuario (`shifts_user_open_unique`),
        // y dos simultáneos tampoco ocurrían en la vida real.
        $previo = $this->makeShift(['closed_at' => now()->subHour()]);
        $this->makeCashExpense($previo, 400);

        $shift = $this->makeShift();

        $result = (new ShiftCashOutCalculator)->forShift($shift, totalCash: 0, totalWithdrawals: 0);

        $this->assertSame(0.0, $result['cash_expenses']);
    }
}
