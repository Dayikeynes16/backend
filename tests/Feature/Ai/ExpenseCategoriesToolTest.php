<?php

namespace Tests\Feature\Ai;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\Assistant\Tools\ExpenseCategoriesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseCategoriesToolTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ExpenseSubcategory $gasolina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $transporte = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Transporte', 'description' => 'Traslados', 'status' => 'active']);
        ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Obsoleta', 'status' => 'inactive']);
        $this->gasolina = ExpenseSubcategory::create(['tenant_id' => $this->tenant->id, 'expense_category_id' => $transporte->id, 'name' => 'Gasolina', 'status' => 'active']);
    }

    private function runTool(User $user, array $over = []): array
    {
        $tool = app(ExpenseCategoriesTool::class);
        $params = $tool->validate($user, array_merge(['include_inactive' => true, 'category_name' => null], $over));

        return $tool->execute($user, $params)->data;
    }

    private function makeExpense(int $branchId): void
    {
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'user_id' => $this->adminEmpresa->id,
            'expense_subcategory_id' => $this->gasolina->id,
            'concept' => 'x',
            'amount' => 100,
            'payment_method' => PaymentMethod::Cash->value,
            'expense_at' => now(),
        ]);
    }

    public function test_lists_full_catalog_including_inactive(): void
    {
        $data = $this->runTool($this->adminEmpresa);

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['active']);
        $this->assertSame(1, $data['inactive']);
        $names = array_column($data['categories'], 'name');
        $this->assertContains('Obsoleta', $names);
    }

    public function test_excludes_inactive_when_requested(): void
    {
        $data = $this->runTool($this->adminEmpresa, ['include_inactive' => false]);

        $this->assertSame(1, $data['total']);
        $this->assertSame('Transporte', $data['categories'][0]['name']);
    }

    public function test_filters_to_single_category_with_subcategory_counts(): void
    {
        $this->makeExpense($this->branch->id);

        $data = $this->runTool($this->adminEmpresa, ['category_name' => 'Transporte']);

        $this->assertSame(1, $data['total']);
        $cat = $data['categories'][0];
        $this->assertSame(1, $cat['expense_count']);
        $this->assertSame('Gasolina', $cat['subcategories'][0]['name']);
        $this->assertSame(1, $cat['subcategories'][0]['expense_count']);
    }

    public function test_admin_sucursal_expense_count_scoped_to_own_branch(): void
    {
        $this->makeExpense($this->branch->id);       // sucursal del admin-sucursal
        $this->makeExpense($this->secondBranch->id); // otra sucursal

        // admin-empresa ve ambas.
        $empresa = $this->runTool($this->adminEmpresa, ['category_name' => 'Transporte']);
        $this->assertSame(2, $empresa['categories'][0]['subcategories'][0]['expense_count']);

        // admin-sucursal sólo la suya.
        $sucursal = $this->runTool($this->adminSucursal, ['category_name' => 'Transporte']);
        $this->assertSame(1, $sucursal['categories'][0]['subcategories'][0]['expense_count']);
    }
}
