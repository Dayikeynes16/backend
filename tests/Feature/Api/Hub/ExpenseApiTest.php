<?php

namespace Tests\Feature\Api\Hub;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ExpenseSubcategory $subcategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active',
        ]);
        $this->subcategory = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id, 'expense_category_id' => $category->id,
            'name' => 'Luz', 'status' => 'active',
        ]);
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    private function openShift(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
    }

    public function test_store_requires_open_shift(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/hub/expenses', [
                'concept' => 'Recibo CFE', 'amount' => 150, 'expense_subcategory_id' => $this->subcategory->id,
            ])
            ->assertStatus(409);
    }

    public function test_store_creates_cash_expense_tied_to_shift(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/expenses', [
                'concept' => 'Recibo CFE', 'amount' => 150, 'expense_subcategory_id' => $this->subcategory->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.concept', 'Recibo CFE')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.subcategory.name', 'Luz');

        $this->assertSame(1, Expense::where('user_id', $this->cajero->id)->count());
    }

    public function test_index_lists_user_expenses_and_categories(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)->postJson('/api/v1/hub/expenses', [
            'concept' => 'Recibo CFE', 'amount' => 150, 'expense_subcategory_id' => $this->subcategory->id,
        ])->assertCreated();

        $res = $this->withToken($token)->getJson('/api/v1/hub/expenses')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals(150.0, $res->json('total'));
        $this->assertSame('Servicios', $res->json('categories.0.name'));
        $this->assertSame('Luz', $res->json('categories.0.subcategories.0.name'));
    }

    public function test_validation_rejects_subcategory_of_other_tenant(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)
            ->postJson('/api/v1/hub/expenses', [
                'concept' => 'x', 'amount' => 10, 'expense_subcategory_id' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_admin_empresa_forbidden(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/expenses')
            ->assertStatus(403);
    }
}
