<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\PreparesDraft;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\Tools\PrepareProviderDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PrepareProviderDraftToolTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tool(): PrepareProviderDraftTool
    {
        return app(PrepareProviderDraftTool::class);
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
            'content' => 'agrega un proveedor',
        ]);

        return new ToolContext($session, $msg, []);
    }

    private function makeProvider(string $name, string $type = 'insumos'): Provider
    {
        return Provider::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'type' => $type,
            'status' => 'active',
        ]);
    }

    public function test_prepares_provider_draft_without_creating_provider(): void
    {
        $result = $this->tool()->prepareDraft($this->adminEmpresa, $this->tool()->validate($this->adminEmpresa, [
            'name' => 'Carnes del Norte',
            'type' => 'mayorista_carne',
            'phone' => '5512345678',
            'email' => null,
            'rfc' => null,
            'address' => null,
            'notes' => null,
        ]), $this->context($this->adminEmpresa));

        $this->assertSame('assistant_draft', $result->kind);
        $this->assertSame(0, Provider::count(), 'La tool no debe crear el proveedor.');
        $this->assertSame(1, AssistantDraft::count());

        $draft = AssistantDraft::first();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertSame('Carnes del Norte', $draft->payload['name']);
        $this->assertSame('mayorista_carne', $draft->payload['type']);
        $this->assertSame([], $draft->payload['campos_faltantes']);
    }

    public function test_detects_possible_duplicates_by_similar_name(): void
    {
        $this->makeProvider('Distribuidora La Unión');

        $result = $this->tool()->prepareDraft($this->adminEmpresa, $this->tool()->validate($this->adminEmpresa, [
            'name' => 'Distribuidora La Unión Norte',
            'type' => 'insumos',
            'phone' => null, 'email' => null, 'rfc' => null, 'address' => null, 'notes' => null,
        ]), $this->context($this->adminEmpresa));

        $names = collect($result->data['duplicates'])->pluck('name')->all();
        $this->assertContains('Distribuidora La Unión', $names);
    }

    public function test_flags_missing_name_and_type(): void
    {
        $this->tool()->prepareDraft($this->adminEmpresa, $this->tool()->validate($this->adminEmpresa, [
            'name' => null, 'type' => null,
            'phone' => null, 'email' => null, 'rfc' => null, 'address' => null, 'notes' => null,
        ]), $this->context($this->adminEmpresa));

        $missing = AssistantDraft::first()->payload['campos_faltantes'];
        $this->assertContains('nombre', $missing);
        $this->assertContains('tipo', $missing);
    }

    public function test_admin_sucursal_gated_by_branch_feature(): void
    {
        $tool = $this->tool();

        // Por defecto la sucursal NO tiene el catálogo de proveedores habilitado.
        $this->assertFalse($tool->authorize($this->adminSucursal, []));

        $this->branch->update(['branch_admin_providers_enabled' => true]);
        $this->assertTrue($tool->authorize($this->adminSucursal->refresh(), []));

        // admin-empresa siempre puede.
        $this->assertTrue($tool->authorize($this->adminEmpresa, []));
    }

    public function test_tool_is_write_and_prepare_only(): void
    {
        $tool = $this->tool();
        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);
    }
}
