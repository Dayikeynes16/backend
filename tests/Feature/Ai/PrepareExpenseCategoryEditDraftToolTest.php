<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\PreparesDraft;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\Tools\PrepareExpenseCategoryEditDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PrepareExpenseCategoryEditDraftToolTest extends TestCase
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
            'description' => 'Gastos de transporte',
            'status' => 'active',
        ]);
    }

    private function tool(): PrepareExpenseCategoryEditDraftTool
    {
        return app(PrepareExpenseCategoryEditDraftTool::class);
    }

    private function context(User $user): ToolContext
    {
        $session = AiAssistantSession::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'message_count' => 0]);
        $msg = AiAssistantMessage::create(['session_id' => $session->id, 'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'user', 'content' => 'edita']);

        return new ToolContext($session, $msg, []);
    }

    private function baseParams(array $over = []): array
    {
        return array_merge([
            'tipo' => 'categoria', 'nombre_actual' => 'Transporte', 'categoria_padre_nombre' => null,
            'nuevo_nombre' => null, 'nueva_descripcion' => null, 'nuevo_estado' => null,
        ], $over);
    }

    public function test_resolves_category_target_and_proposes_rename(): void
    {
        $params = $this->tool()->validate($this->adminEmpresa, $this->baseParams(['nuevo_nombre' => 'Logística']));
        $this->tool()->prepareDraft($this->adminEmpresa, $params, $this->context($this->adminEmpresa));

        $draft = AssistantDraft::first();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertSame($this->transporte->id, $draft->payload['target_id']);
        $this->assertSame('Transporte', $draft->payload['current_name']);
        $this->assertSame('Logística', $draft->payload['effective']['name']);
        // La descripción y el estado no cambiaron → conservan lo actual.
        $this->assertSame('Gastos de transporte', $draft->payload['effective']['description']);
        $this->assertSame('active', $draft->payload['effective']['status']);
    }

    public function test_resolves_subcategory_by_name_and_parent(): void
    {
        $sub = ExpenseSubcategory::create(['tenant_id' => $this->tenant->id, 'expense_category_id' => $this->transporte->id, 'name' => 'Gasolina', 'status' => 'active']);

        $params = $this->tool()->validate($this->adminEmpresa, $this->baseParams([
            'tipo' => 'subcategoria', 'nombre_actual' => 'Gasolina', 'categoria_padre_nombre' => 'Transporte', 'nuevo_estado' => 'inactivo',
        ]));

        $this->assertSame($sub->id, $params['target_id']);
        $this->assertSame('inactive', $params['new_status']);
    }

    public function test_unresolved_target_is_flagged_missing(): void
    {
        $this->tool()->prepareDraft(
            $this->adminEmpresa,
            $this->tool()->validate($this->adminEmpresa, $this->baseParams(['nombre_actual' => 'NoExiste'])),
            $this->context($this->adminEmpresa),
        );

        $this->assertContains('categoría', AssistantDraft::first()->payload['campos_faltantes']);
    }

    public function test_gated_by_branch_feature_and_prepare_only(): void
    {
        $tool = $this->tool();
        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);

        $this->assertFalse($tool->authorize($this->adminSucursal, []));
        $this->branch->update(['branch_admin_expense_categories_enabled' => true]);
        $this->assertTrue($tool->authorize($this->adminSucursal->refresh(), []));
    }
}
