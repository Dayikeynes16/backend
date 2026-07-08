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

class AssistantExpenseCategoryConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeCategory(string $name): ExpenseCategory
    {
        return ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function makeDraft(User $user): AssistantDraft
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
            'content' => 'crea categoría',
        ]);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::ExpenseCategory->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['tipo' => 'categoria', 'nombre' => 'Mantenimiento'],
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function confirmUrl(string $prefix, AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_creates_category(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), ['tipo' => 'categoria', 'nombre' => 'Mantenimiento', 'descripcion' => 'Reparaciones'])
            ->assertOk();

        $this->assertSame(1, ExpenseCategory::where('name', 'Mantenimiento')->count());
        $this->assertSame(AiDraftStatus::Consumed, $draft->refresh()->status);
    }

    public function test_confirm_creates_subcategory_under_existing_category(): void
    {
        $parent = $this->makeCategory('Transporte');
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), [
                'tipo' => 'subcategoria',
                'nombre' => 'Gasolina',
                'existing_category_id' => $parent->id,
            ])
            ->assertOk();

        $this->assertSame(1, ExpenseSubcategory::where('expense_category_id', $parent->id)->where('name', 'Gasolina')->count());
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $this->makeCategory('Mantenimiento');
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), ['tipo' => 'categoria', 'nombre' => 'Mantenimiento'])
            ->assertStatus(422);

        $this->assertSame(1, ExpenseCategory::count());
    }

    public function test_duplicate_subcategory_name_is_rejected(): void
    {
        $parent = $this->makeCategory('Transporte');
        ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $parent->id,
            'name' => 'Gasolina',
            'status' => 'active',
        ]);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), [
                'tipo' => 'subcategoria',
                'nombre' => 'Gasolina',
                'existing_category_id' => $parent->id,
            ])
            ->assertStatus(422);

        $this->assertSame(1, ExpenseSubcategory::count());
    }

    public function test_admin_sucursal_without_feature_cannot_confirm(): void
    {
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), ['tipo' => 'categoria', 'nombre' => 'Mantenimiento'])
            ->assertStatus(403);

        $this->assertSame(0, ExpenseCategory::count());
    }

    public function test_admin_sucursal_with_feature_can_confirm(): void
    {
        $this->branch->update(['branch_admin_expense_categories_enabled' => true]);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), ['tipo' => 'categoria', 'nombre' => 'Mantenimiento'])
            ->assertOk();

        $this->assertSame(1, ExpenseCategory::where('name', 'Mantenimiento')->count());
    }

    public function test_user_cannot_confirm_another_users_draft(): void
    {
        $this->branch->update(['branch_admin_expense_categories_enabled' => true]);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), ['tipo' => 'categoria', 'nombre' => 'Mantenimiento'])
            ->assertNotFound();

        $this->assertSame(0, ExpenseCategory::count());
    }
}
