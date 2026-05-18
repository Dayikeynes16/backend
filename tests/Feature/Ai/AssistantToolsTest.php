<?php

namespace Tests\Feature\Ai;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\Ai\Assistant\Tools\CustomerStatsTool;
use App\Services\Ai\Assistant\Tools\ExpenseSummaryTool;
use App\Services\Ai\Assistant\Tools\ProductDetailsTool;
use App\Services\Ai\Assistant\Tools\SalesSummaryTool;
use App\Services\Ai\Assistant\Tools\ShiftStatusTool;
use App\Services\Ai\Assistant\Tools\TopProductsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantToolsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_sales_summary_tool_returns_today_total_for_admin_empresa(): void
    {
        $this->makeCompletedSale([
            'branch_id' => $this->branch->id,
            'total' => 500,
            'amount_paid' => 500,
            'completed_at' => now(),
        ]);
        $this->makeCompletedSale([
            'branch_id' => $this->secondBranch->id,
            'total' => 200,
            'amount_paid' => 200,
            'completed_at' => now(),
        ]);

        /** @var SalesSummaryTool $tool */
        $tool = app(SalesSummaryTool::class);
        $params = $tool->validate($this->adminEmpresa, [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => null,
        ]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertSame('sales_summary', $result->kind);
        $this->assertEquals(700.0, $result->data['net_sales']);
        $this->assertSame(2, $result->data['ticket_count']);
    }

    public function test_sales_summary_tool_scopes_admin_sucursal_to_own_branch(): void
    {
        $this->makeCompletedSale([
            'branch_id' => $this->branch->id,
            'total' => 500,
            'amount_paid' => 500,
            'completed_at' => now(),
        ]);
        $this->makeCompletedSale([
            'branch_id' => $this->secondBranch->id,
            'total' => 9999,
            'amount_paid' => 9999,
            'completed_at' => now(),
        ]);

        /** @var SalesSummaryTool $tool */
        $tool = app(SalesSummaryTool::class);

        // El admin-sucursal pide explícitamente OTRA sucursal — debe ignorarse.
        $params = $tool->validate($this->adminSucursal, [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => $this->secondBranch->name,
        ]);

        $this->assertSame($this->branch->id, $params['branch_id'], 'branch_id debe forzarse a la sucursal del usuario');
        $result = $tool->execute($this->adminSucursal, $params);

        $this->assertEquals(500.0, $result->data['net_sales']);
        $this->assertSame(1, $result->data['ticket_count']);
    }

    public function test_expense_summary_tool_aggregates_by_subcategory(): void
    {
        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'status' => 'active',
        ]);
        $sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Gasolina',
            'status' => 'active',
        ]);

        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'expense_subcategory_id' => $sub->id,
            'concept' => 'Carga',
            'amount' => 850,
            'payment_method' => PaymentMethod::Cash->value,
            'expense_at' => now(),
        ]);
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'expense_subcategory_id' => $sub->id,
            'concept' => 'Otra carga',
            'amount' => 150,
            'payment_method' => PaymentMethod::Card->value,
            'expense_at' => now(),
        ]);

        /** @var ExpenseSummaryTool $tool */
        $tool = app(ExpenseSummaryTool::class);
        $params = $tool->validate($this->adminEmpresa, [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => null,
            'top_limit' => 5,
        ]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertSame('expense_summary', $result->kind);
        $this->assertEquals(1000.0, $result->data['total']);
        $this->assertSame(2, $result->data['count']);
        $this->assertSame('Gasolina', $result->data['top_subcategories'][0]['subcategory']);
    }

    public function test_admin_sucursal_role_is_in_allowed_roles_for_read_tools(): void
    {
        foreach ([
            SalesSummaryTool::class,
            ExpenseSummaryTool::class,
            TopProductsTool::class,
            ShiftStatusTool::class,
            CustomerStatsTool::class,
            ProductDetailsTool::class,
        ] as $cls) {
            $tool = app($cls);
            $this->assertContains('admin-empresa', $tool->rolesAllowed(), $cls);
            $this->assertContains('admin-sucursal', $tool->rolesAllowed(), $cls);
        }
    }

    public function test_product_details_tool_searches_by_name(): void
    {
        $this->makeProduct(['name' => 'Pulpa de res', 'price' => 220, 'cost_price' => 150, 'unit_type' => 'kg']);
        $this->makeProduct(['name' => 'Bistec de res', 'price' => 180]);
        $this->makeProduct(['name' => 'Pollo entero', 'price' => 90]);

        /** @var ProductDetailsTool $tool */
        $tool = app(ProductDetailsTool::class);
        $params = $tool->validate($this->adminEmpresa, [
            'name_query' => 'res',
            'category_name' => null,
            'branch_name' => null,
            'limit' => 10,
        ]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertSame('product_details', $result->kind);
        $names = collect($result->data['products'])->pluck('name')->all();
        $this->assertContains('Pulpa de res', $names);
        $this->assertContains('Bistec de res', $names);
        $this->assertNotContains('Pollo entero', $names);
    }

    public function test_product_details_tool_admin_sucursal_scoped_to_own_branch(): void
    {
        $this->makeProduct(['branch_id' => $this->branch->id, 'name' => 'Producto A']);
        $this->makeProduct(['branch_id' => $this->secondBranch->id, 'name' => 'Producto B']);

        /** @var ProductDetailsTool $tool */
        $tool = app(ProductDetailsTool::class);
        // admin-sucursal pidiendo explícitamente la otra sucursal — debe ignorarse.
        $params = $tool->validate($this->adminSucursal, [
            'name_query' => null,
            'category_name' => null,
            'branch_name' => $this->secondBranch->name,
            'limit' => 10,
        ]);
        $this->assertSame($this->branch->id, $params['branch_id']);

        $result = $tool->execute($this->adminSucursal, $params);
        $names = collect($result->data['products'])->pluck('name')->all();
        $this->assertContains('Producto A', $names);
        $this->assertNotContains('Producto B', $names);
    }

    public function test_product_details_tool_returns_empty_when_category_not_found(): void
    {
        $this->makeProduct(['name' => 'X']);

        /** @var ProductDetailsTool $tool */
        $tool = app(ProductDetailsTool::class);
        $params = $tool->validate($this->adminEmpresa, [
            'name_query' => null,
            'category_name' => 'CategoriaInexistente',
            'branch_name' => null,
            'limit' => 10,
        ]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertFalse($result->data['category_found']);
        $this->assertSame([], $result->data['products']);
    }
}
