<?php

namespace Tests\Feature\Ai;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\Ai\Assistant\Tools\ExpenseSummaryTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseSummaryFilterTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ExpenseSubcategory $gasolina;

    private ExpenseSubcategory $luz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $transporte = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Transporte', 'status' => 'active']);
        $servicios = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active']);
        $this->gasolina = ExpenseSubcategory::create(['tenant_id' => $this->tenant->id, 'expense_category_id' => $transporte->id, 'name' => 'Gasolina', 'status' => 'active']);
        $this->luz = ExpenseSubcategory::create(['tenant_id' => $this->tenant->id, 'expense_category_id' => $servicios->id, 'name' => 'Luz', 'status' => 'active']);

        $this->makeExpense($this->gasolina->id, 850);
        $this->makeExpense($this->luz->id, 1200);
    }

    private function makeExpense(int $subId, float $amount): void
    {
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminEmpresa->id,
            'expense_subcategory_id' => $subId,
            'concept' => 'x',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Cash->value,
            'expense_at' => now(),
        ]);
    }

    private function runTool(array $over): ToolResult
    {
        $tool = app(ExpenseSummaryTool::class);
        $params = $tool->validate($this->adminEmpresa, array_merge([
            'scope' => 'today', 'date_from' => null, 'date_to' => null,
            'branch_name' => null, 'category_name' => null, 'subcategory_name' => null, 'top_limit' => 5,
        ], $over));

        return $tool->execute($this->adminEmpresa, $params);
    }

    public function test_filters_total_by_category(): void
    {
        $result = $this->runTool(['category_name' => 'Transporte']);

        $this->assertEqualsWithDelta(850.0, $result->data['total'], 0.01);
        $this->assertSame(1, $result->data['count']);
        $this->assertTrue($result->data['filter_found']);
    }

    public function test_filters_total_by_subcategory(): void
    {
        $result = $this->runTool(['subcategory_name' => 'Luz']);

        $this->assertEqualsWithDelta(1200.0, $result->data['total'], 0.01);
        $this->assertSame(1, $result->data['count']);
    }

    public function test_unknown_category_returns_zero_and_flags_not_found(): void
    {
        $result = $this->runTool(['category_name' => 'NoExiste']);

        $this->assertEqualsWithDelta(0.0, $result->data['total'], 0.01);
        $this->assertFalse($result->data['filter_found']);
    }
}
