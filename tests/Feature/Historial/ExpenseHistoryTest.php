<?php

namespace Tests\Feature\Historial;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseHistoryTest extends TestCase
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
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Insumos',
            'status' => 'active',
        ])->id;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'concept' => 'Bolsas',
            'amount' => 50,
            'expense_subcategory_id' => $this->subId(),
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], $override);
    }

    public function test_admin_create_edit_cancel_writes_history(): void
    {
        $this->actingAs($this->adminEmpresa);
        $sub = $this->subId();

        $this->post(route('empresa.gastos.store', $this->tenant->slug), $this->payload(['expense_subcategory_id' => $sub]))->assertRedirect();
        $expense = Expense::firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $expense->id, 'event' => AuditEvent::Created->value]);

        $this->put(route('empresa.gastos.update', ['tenant' => $this->tenant->slug, 'gasto' => $expense->id]),
            $this->payload(['amount' => 75, 'expense_subcategory_id' => $sub]))->assertRedirect();
        $log = AuditLog::where('event', AuditEvent::Updated->value)->firstOrFail();
        $this->assertEquals([50.0, 75.0], $log->changes['fields']['amount']);

        $this->delete(route('empresa.gastos.destroy', ['tenant' => $this->tenant->slug, 'gasto' => $expense->id]),
            ['cancellation_reason' => 'error'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $expense->id, 'event' => AuditEvent::Cancelled->value]);
    }
}
