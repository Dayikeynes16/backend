<?php

namespace Tests\Feature\Empresa;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_admin_empresa_can_load_dashboard(): void
    {
        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.dashboard', $this->tenant->slug));
        $response->assertOk();

        $page = $response->viewData('page');
        $props = $page['props'];

        $this->assertArrayHasKey('totals', $props);
        $this->assertArrayHasKey('hoursData', $props);
        $this->assertArrayHasKey('expenses', $props);
        $this->assertArrayHasKey('branches', $props);
        $this->assertArrayHasKey('paymentMethods', $props);
    }

    public function test_default_view_aggregates_all_branches(): void
    {
        // Venta en branch 1
        Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F1', 'payment_method' => 'cash',
            'total' => 500, 'amount_paid' => 500, 'amount_pending' => 0,
            'status' => SaleStatus::Completed, 'origin' => 'admin',
            'completed_at' => now(),
        ]);
        // Venta en branch 2
        Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F2', 'payment_method' => 'cash',
            'total' => 700, 'amount_paid' => 700, 'amount_pending' => 0,
            'status' => SaleStatus::Completed, 'origin' => 'admin',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.dashboard', $this->tenant->slug));
        $totals = $response->viewData('page')['props']['totals'];
        // Suma de las 2 sucursales
        $this->assertEquals(1200, $totals['total_sales']);
        $this->assertEquals(2, $totals['sale_count']);
    }

    public function test_branch_filter_restricts_to_selected_branch(): void
    {
        Sale::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'folio' => 'F1', 'payment_method' => 'cash',
            'total' => 500, 'amount_paid' => 500, 'amount_pending' => 0,
            'status' => SaleStatus::Completed, 'origin' => 'admin', 'completed_at' => now(),
        ]);
        Sale::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->secondBranch->id, 'user_id' => $this->cajero->id,
            'folio' => 'F2', 'payment_method' => 'cash',
            'total' => 700, 'amount_paid' => 700, 'amount_pending' => 0,
            'status' => SaleStatus::Completed, 'origin' => 'admin', 'completed_at' => now(),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.dashboard', $this->tenant->slug).'?branch_id='.$this->secondBranch->id);
        $totals = $response->viewData('page')['props']['totals'];
        $this->assertEquals(700, $totals['total_sales']);
        $this->assertEquals(1, $totals['sale_count']);
    }

    public function test_expenses_block_aggregates_across_branches(): void
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active']);
        $sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id, 'expense_category_id' => $cat->id,
            'name' => 'Luz', 'status' => 'active',
        ]);

        Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $sub->id, 'user_id' => $this->adminEmpresa->id,
            'concept' => 'Luz B1', 'amount' => 100, 'expense_at' => now(),
        ]);
        Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->secondBranch->id,
            'expense_subcategory_id' => $sub->id, 'user_id' => $this->adminEmpresa->id,
            'concept' => 'Luz B2', 'amount' => 250, 'expense_at' => now(),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.dashboard', $this->tenant->slug));
        $expenses = $response->viewData('page')['props']['expenses'];

        $this->assertEquals(350, $expenses['total']);
        $this->assertEquals(2, $expenses['count']);
        $this->assertNotEmpty($expenses['top_categories']);
        $this->assertEquals(350, $expenses['top_categories'][0]['total']);
    }

    public function test_expenses_block_filtered_to_branch(): void
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active']);
        $sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id, 'expense_category_id' => $cat->id,
            'name' => 'Luz', 'status' => 'active',
        ]);

        Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $sub->id, 'user_id' => $this->adminEmpresa->id,
            'concept' => 'Luz B1', 'amount' => 100, 'expense_at' => now(),
        ]);
        Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->secondBranch->id,
            'expense_subcategory_id' => $sub->id, 'user_id' => $this->adminEmpresa->id,
            'concept' => 'Luz B2', 'amount' => 250, 'expense_at' => now(),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.dashboard', $this->tenant->slug).'?branch_id='.$this->secondBranch->id);
        $expenses = $response->viewData('page')['props']['expenses'];
        $this->assertEquals(250, $expenses['total']);
        $this->assertEquals(1, $expenses['count']);
    }

    public function test_other_tenant_data_is_not_visible(): void
    {
        // Crear otro tenant con datos
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-tenant', 'status' => 'active']);
        $otherBranch = Branch::create(['tenant_id' => $other->id, 'name' => 'X', 'address' => 'Y', 'status' => 'active']);
        $otherUser = User::create([
            'tenant_id' => $other->id, 'branch_id' => $otherBranch->id,
            'name' => 'cajero', 'email' => 'c@other.test', 'password' => bcrypt('x'),
        ]);
        Sale::create([
            'tenant_id' => $other->id, 'branch_id' => $otherBranch->id, 'user_id' => $otherUser->id,
            'folio' => 'OTHER1', 'payment_method' => 'cash',
            'total' => 9999, 'amount_paid' => 9999, 'amount_pending' => 0,
            'status' => SaleStatus::Completed, 'origin' => 'admin', 'completed_at' => now(),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.dashboard', $this->tenant->slug));
        $totals = $response->viewData('page')['props']['totals'];
        // El total del otro tenant NO debe aparecer
        $this->assertEquals(0, $totals['total_sales']);
    }

    public function test_cajero_cannot_access_empresa_dashboard(): void
    {
        $this->actingAs($this->cajero);
        $response = $this->get(route('empresa.dashboard', $this->tenant->slug));
        $response->assertForbidden();
    }

    public function test_admin_sucursal_dashboard_includes_expenses_block(): void
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active']);
        $sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id, 'expense_category_id' => $cat->id,
            'name' => 'Luz', 'status' => 'active',
        ]);
        Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $sub->id, 'user_id' => $this->adminSucursal->id,
            'concept' => 'Luz', 'amount' => 100, 'expense_at' => now(),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.dashboard', $this->tenant->slug));
        $response->assertOk();
        $expenses = $response->viewData('page')['props']['expenses'];
        $this->assertEquals(100, $expenses['total']);
        $this->assertEquals(1, $expenses['count']);
    }
}
