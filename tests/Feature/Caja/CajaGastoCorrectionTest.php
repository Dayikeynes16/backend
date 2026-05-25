<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaGastoCorrectionTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'expense_category_id' => $cat->id, 'name' => 'Insumos', 'status' => 'active',
        ])->id;
    }

    private function openShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'opened_at' => now(), 'opening_amount' => 1000,
        ]);
    }

    private function expense(CashRegisterShift $shift, ?int $userId = null): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id, 'expense_subcategory_id' => $this->subId(),
            'user_id' => $userId ?? $this->cajero->id, 'concept' => 'Bolsas', 'amount' => 50,
            'payment_method' => 'cash', 'expense_at' => now(),
        ]);
    }

    public function test_cajero_edits_own_open_shift_expense(): void
    {
        $shift = $this->openShift();
        $e = $this->expense($shift);

        $this->actingAs($this->cajero);
        $this->put(route('caja.gastos.update', ['tenant' => $this->tenant->slug, 'gasto' => $e->id]), [
            'concept' => 'Bolsas grandes', 'amount' => 80, 'expense_subcategory_id' => $e->expense_subcategory_id,
        ])->assertRedirect();

        $this->assertSame('80.00', $e->refresh()->amount);
    }

    public function test_cajero_cancels_own_open_shift_expense(): void
    {
        $shift = $this->openShift();
        $e = $this->expense($shift);

        $this->actingAs($this->cajero);
        $this->delete(route('caja.gastos.destroy', ['tenant' => $this->tenant->slug, 'gasto' => $e->id]),
            ['cancellation_reason' => 'duplicado'])->assertRedirect();

        $this->assertSoftDeleted('expenses', ['id' => $e->id]);
    }

    public function test_cajero_cannot_edit_closed_shift_expense(): void
    {
        $shift = $this->openShift();
        $e = $this->expense($shift);
        $shift->update(['closed_at' => now()]);

        $this->actingAs($this->cajero);
        $this->put(route('caja.gastos.update', ['tenant' => $this->tenant->slug, 'gasto' => $e->id]), [
            'concept' => 'X', 'amount' => 80, 'expense_subcategory_id' => $e->expense_subcategory_id,
        ])->assertForbidden();
    }
}
