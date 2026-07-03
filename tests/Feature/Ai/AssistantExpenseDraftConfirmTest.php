<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantExpenseDraftConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ExpenseSubcategory $subcategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Servicios',
            'status' => 'active',
        ]);
        $this->subcategory = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Luz',
            'status' => 'active',
        ]);
    }

    private function makeDraft(User $user, array $attrs = []): AssistantDraft
    {
        app()->instance('tenant', $this->tenant);

        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'message_count' => 0,
        ]);
        $msg = AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'pagué la luz',
        ]);

        return AssistantDraft::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::Expense->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['concepto' => 'Luz', 'monto' => 1200],
            'expires_at' => now()->addHours(6),
        ], $attrs));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'concept' => 'Recibo de luz',
            'amount' => 1200,
            'expense_subcategory_id' => $this->subcategory->id,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'transfer',
            'description' => null,
            'branch_id' => $this->branch->id,
        ], $over);
    }

    private function confirmUrl(string $prefix, AssistantDraft $draft): string
    {
        return route("{$prefix}.asistente.drafts.confirm", ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    private function cancelUrl(string $prefix, AssistantDraft $draft): string
    {
        return route("{$prefix}.asistente.drafts.cancel", ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_creates_expense_consumes_draft_and_audits(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload())
            ->assertOk();

        $this->assertSame(1, Expense::count());
        $expense = Expense::first();
        $this->assertEquals(1200.0, (float) $expense->amount);
        $this->assertSame($this->subcategory->id, $expense->expense_subcategory_id);
        $this->assertSame($this->branch->id, $expense->branch_id);
        $this->assertSame($this->adminEmpresa->id, $expense->user_id);

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($expense->id, $draft->result_id);
        $this->assertNotNull($draft->confirmed_at);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $expense->getMorphClass(),
            'auditable_id' => $expense->id,
            'event' => 'created',
        ]);
    }

    public function test_double_confirm_is_rejected(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)->postJson($this->confirmUrl('empresa', $draft), $this->payload())->assertOk();
        $this->actingAs($this->adminEmpresa)->postJson($this->confirmUrl('empresa', $draft), $this->payload())->assertStatus(409);

        $this->assertSame(1, Expense::count());
    }

    public function test_admin_sucursal_branch_is_forced_ignoring_tampered_payload(): void
    {
        $draft = $this->makeDraft($this->adminSucursal);

        // El cliente manipula branch_id apuntando a otra sucursal.
        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload(['branch_id' => $this->secondBranch->id]))
            ->assertOk();

        $this->assertSame($this->branch->id, Expense::first()->branch_id);
    }

    public function test_confirm_rejects_invalid_subcategory(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload(['expense_subcategory_id' => 999999]))
            ->assertStatus(422);

        $this->assertSame(0, Expense::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_expired_draft_cannot_be_confirmed(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa, ['expires_at' => now()->subHour()]);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload())
            ->assertStatus(409);

        $this->assertSame(0, Expense::count());
    }

    public function test_cajero_cannot_reach_confirm_route(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->cajero)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Expense::count());
    }

    public function test_user_cannot_confirm_another_users_draft(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        // admin-sucursal (otro usuario) intenta confirmar el borrador ajeno.
        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload())
            ->assertNotFound();

        $this->assertSame(0, Expense::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_cancel_marks_draft_cancelled(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->cancelUrl('empresa', $draft))
            ->assertOk();

        $this->assertSame(AiDraftStatus::Cancelled, $draft->refresh()->status);
        $this->assertSame(0, Expense::count());
    }
}
