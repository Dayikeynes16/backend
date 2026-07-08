<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\PreparesDraft;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolRegistry;
use App\Services\Ai\Assistant\Tools\PrepareExpenseDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PrepareExpenseDraftToolTest extends TestCase
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
            'name' => 'Transporte',
            'status' => 'active',
        ]);
        $this->subcategory = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Gasolina',
            'status' => 'active',
        ]);
    }

    private function tool(): PrepareExpenseDraftTool
    {
        return app(PrepareExpenseDraftTool::class);
    }

    private function context(User $user, string $text = 'gasolina 850 hoy efectivo'): ToolContext
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
            'content' => $text,
        ]);

        return new ToolContext($session, $msg, []);
    }

    public function test_prepares_expense_draft_from_text_without_creating_expense(): void
    {
        $tool = $this->tool();
        $params = $tool->validate($this->adminEmpresa, [
            'concepto' => 'Gasolina camioneta',
            'monto' => 850,
            'fecha' => null,
            'metodo_pago' => 'cash',
            'categoria_nombre' => 'Transporte',
            'subcategoria_nombre' => 'Gasolina',
            'branch_name' => $this->branch->name,
            'descripcion' => null,
        ]);

        $result = $tool->prepareDraft($this->adminEmpresa, $params, $this->context($this->adminEmpresa));

        $this->assertSame('assistant_draft', $result->kind);
        $this->assertSame(0, Expense::count(), 'La tool no debe crear el gasto.');
        $this->assertSame(1, AssistantDraft::count());

        $draft = AssistantDraft::first();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertEquals(850.0, $draft->payload['monto']);
        $this->assertSame('cash', $draft->payload['metodo_pago']);
        $this->assertSame($this->subcategory->id, $draft->payload['expense_subcategory_id']);
        $this->assertSame($this->branch->id, $draft->payload['branch_id']);
        $this->assertSame(now()->toDateString(), $draft->payload['fecha']);
        $this->assertSame($this->adminEmpresa->id, $draft->user_id);
    }

    public function test_invented_subcategory_is_dropped_and_flagged_missing(): void
    {
        $tool = $this->tool();
        $params = $tool->validate($this->adminEmpresa, [
            'concepto' => 'Algo',
            'monto' => 100,
            'fecha' => null,
            'metodo_pago' => null,
            'categoria_nombre' => null,
            'subcategoria_nombre' => 'NoExisteEnCatalogo',
            'branch_name' => $this->branch->name,
            'descripcion' => null,
        ]);

        $this->assertNull($params['expense_subcategory_id']);

        $tool->prepareDraft($this->adminEmpresa, $params, $this->context($this->adminEmpresa));

        $draft = AssistantDraft::first();
        $this->assertNull($draft->payload['expense_subcategory_id']);
        $this->assertContains('subcategoría', $draft->payload['campos_faltantes']);
    }

    public function test_admin_sucursal_branch_is_forced_to_own_branch(): void
    {
        $tool = $this->tool();
        // El admin-sucursal nombra explícitamente OTRA sucursal — debe ignorarse.
        $params = $tool->validate($this->adminSucursal, [
            'concepto' => 'X',
            'monto' => 50,
            'fecha' => null,
            'metodo_pago' => null,
            'categoria_nombre' => 'Transporte',
            'subcategoria_nombre' => 'Gasolina',
            'branch_name' => $this->secondBranch->name,
            'descripcion' => null,
        ]);

        $this->assertSame($this->branch->id, $params['branch_id']);

        $tool->prepareDraft($this->adminSucursal, $params, $this->context($this->adminSucursal));
        $this->assertSame($this->branch->id, AssistantDraft::first()->payload['branch_id']);
    }

    public function test_tool_is_write_and_scoped_to_admin_roles(): void
    {
        $tool = $this->tool();

        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);
        // F5 (D5): cajero incluido, gated por cashier_expenses_enabled en authorize().
        $this->assertSame(['admin-empresa', 'admin-sucursal', 'cajero'], $tool->rolesAllowed());
    }

    public function test_execute_is_disabled_for_prepare_tools(): void
    {
        $this->expectException(\LogicException::class);
        $this->tool()->execute($this->adminEmpresa, []);
    }

    public function test_no_confirm_tool_is_registered_and_write_tools_only_prepare(): void
    {
        $tools = app(ToolRegistry::class)->forUser($this->adminEmpresa);

        foreach ($tools as $t) {
            if (! $t->readOnly()) {
                $this->assertInstanceOf(PreparesDraft::class, $t, $t->name().' de escritura debe ser PreparesDraft');
            }
            $this->assertStringNotContainsString('confirm', $t->name());
            $this->assertStringNotContainsString('registrar', $t->name());
        }

        $names = array_map(fn ($t) => $t->name(), $tools);
        $this->assertContains('preparar_borrador_gasto', $names);
    }
}
