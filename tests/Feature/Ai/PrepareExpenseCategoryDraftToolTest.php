<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\PreparesDraft;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\Tools\PrepareExpenseCategoryDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PrepareExpenseCategoryDraftToolTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tool(): PrepareExpenseCategoryDraftTool
    {
        return app(PrepareExpenseCategoryDraftTool::class);
    }

    private function context(User $user): ToolContext
    {
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

        return new ToolContext($session, $msg, []);
    }

    private function makeCategory(string $name): ExpenseCategory
    {
        return ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    public function test_prepares_category_draft_without_creating_category(): void
    {
        $result = $this->tool()->prepareDraft($this->adminEmpresa, $this->tool()->validate($this->adminEmpresa, [
            'tipo' => 'categoria',
            'nombre' => 'Mantenimiento',
            'descripcion' => 'Reparaciones',
            'categoria_padre_nombre' => null,
        ]), $this->context($this->adminEmpresa));

        $this->assertSame('assistant_draft', $result->kind);
        $this->assertSame(0, ExpenseCategory::count());
        $this->assertSame(1, AssistantDraft::count());

        $draft = AssistantDraft::first();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertSame('categoria', $draft->payload['tipo']);
        $this->assertSame('Mantenimiento', $draft->payload['nombre']);
    }

    public function test_subcategory_resolves_parent_category_by_name(): void
    {
        $parent = $this->makeCategory('Transporte');

        $params = $this->tool()->validate($this->adminEmpresa, [
            'tipo' => 'subcategoria',
            'nombre' => 'Gasolina',
            'descripcion' => null,
            'categoria_padre_nombre' => 'Transporte',
        ]);

        $this->assertSame($parent->id, $params['existing_category_id']);
    }

    public function test_subcategory_without_parent_is_flagged_missing(): void
    {
        $this->tool()->prepareDraft($this->adminEmpresa, $this->tool()->validate($this->adminEmpresa, [
            'tipo' => 'subcategoria',
            'nombre' => 'Gasolina',
            'descripcion' => null,
            'categoria_padre_nombre' => 'NoExiste',
        ]), $this->context($this->adminEmpresa));

        $this->assertContains('categoría padre', AssistantDraft::first()->payload['campos_faltantes']);
    }

    public function test_duplicate_category_name_is_warned(): void
    {
        $this->makeCategory('Mantenimiento');

        $result = $this->tool()->prepareDraft($this->adminEmpresa, $this->tool()->validate($this->adminEmpresa, [
            'tipo' => 'categoria',
            'nombre' => 'Mantenimiento',
            'descripcion' => null,
            'categoria_padre_nombre' => null,
        ]), $this->context($this->adminEmpresa));

        $this->assertNotEmpty($result->data['warnings']);
    }

    public function test_admin_sucursal_gated_by_branch_feature(): void
    {
        $tool = $this->tool();

        $this->assertFalse($tool->authorize($this->adminSucursal, []));

        $this->branch->update(['branch_admin_expense_categories_enabled' => true]);
        $this->assertTrue($tool->authorize($this->adminSucursal->refresh(), []));

        $this->assertTrue($tool->authorize($this->adminEmpresa, []));
    }

    public function test_tool_is_write_and_prepare_only(): void
    {
        $tool = $this->tool();
        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);
    }
}
