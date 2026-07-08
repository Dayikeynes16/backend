<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantExpenseCategoryEditConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ExpenseCategory $transporte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->transporte = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'description' => 'Actual',
            'status' => 'active',
        ]);
    }

    private function makeDraft(User $user): AssistantDraft
    {
        app()->instance('tenant', $this->tenant);
        $session = AiAssistantSession::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'message_count' => 0]);
        $msg = AiAssistantMessage::create(['session_id' => $session->id, 'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'user', 'content' => 'edita']);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::ExpenseCategoryEdit->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['target_type' => 'categoria', 'target_id' => $this->transporte->id],
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function url(string $prefix, AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_renames_category(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->url('empresa', $draft), [
                'target_type' => 'categoria', 'target_id' => $this->transporte->id,
                'name' => 'Logística', 'description' => 'Actual', 'status' => 'active',
            ])->assertOk();

        $this->assertSame('Logística', $this->transporte->refresh()->name);
        $this->assertSame(AiDraftStatus::Consumed, $draft->refresh()->status);
    }

    public function test_confirm_inactivates_category(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->url('empresa', $draft), [
                'target_type' => 'categoria', 'target_id' => $this->transporte->id,
                'name' => 'Transporte', 'description' => 'Actual', 'status' => 'inactive',
            ])->assertOk();

        $this->assertSame('inactive', $this->transporte->refresh()->status);
    }

    public function test_confirm_edits_subcategory(): void
    {
        $sub = ExpenseSubcategory::create(['tenant_id' => $this->tenant->id, 'expense_category_id' => $this->transporte->id, 'name' => 'Gasolina', 'status' => 'active']);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->url('empresa', $draft), [
                'target_type' => 'subcategoria', 'target_id' => $sub->id,
                'name' => 'Combustible', 'description' => null, 'status' => 'active',
            ])->assertOk();

        $this->assertSame('Combustible', $sub->refresh()->name);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active']);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->url('empresa', $draft), [
                'target_type' => 'categoria', 'target_id' => $this->transporte->id,
                'name' => 'Servicios', 'description' => null, 'status' => 'active',
            ])->assertStatus(422);

        $this->assertSame('Transporte', $this->transporte->refresh()->name);
    }

    public function test_duplicate_subcategory_name_in_category_is_rejected(): void
    {
        ExpenseSubcategory::create(['tenant_id' => $this->tenant->id, 'expense_category_id' => $this->transporte->id, 'name' => 'Gasolina', 'status' => 'active']);
        $target = ExpenseSubcategory::create(['tenant_id' => $this->tenant->id, 'expense_category_id' => $this->transporte->id, 'name' => 'Diesel', 'status' => 'active']);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->url('empresa', $draft), [
                'target_type' => 'subcategoria', 'target_id' => $target->id,
                'name' => 'Gasolina', 'description' => null, 'status' => 'active',
            ])->assertStatus(422);

        $this->assertSame('Diesel', $target->refresh()->name);
    }

    public function test_admin_sucursal_without_feature_cannot_confirm(): void
    {
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->url('sucursal', $draft), [
                'target_type' => 'categoria', 'target_id' => $this->transporte->id,
                'name' => 'Logística', 'description' => null, 'status' => 'active',
            ])->assertStatus(403);

        $this->assertSame('Transporte', $this->transporte->refresh()->name);
    }

    public function test_user_cannot_confirm_another_users_draft(): void
    {
        $this->branch->update(['branch_admin_expense_categories_enabled' => true]);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->url('sucursal', $draft), [
                'target_type' => 'categoria', 'target_id' => $this->transporte->id,
                'name' => 'Logística', 'description' => null, 'status' => 'active',
            ])->assertNotFound();
    }
}
