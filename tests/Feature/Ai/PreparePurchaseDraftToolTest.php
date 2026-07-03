<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\PreparesDraft;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\Tools\PreparePurchaseDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PreparePurchaseDraftToolTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tool(): PreparePurchaseDraftTool
    {
        return app(PreparePurchaseDraftTool::class);
    }

    private function context(User $user, array $attachments = []): ToolContext
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
            'content' => 'compré carne',
        ]);

        return new ToolContext($session, $msg, $attachments);
    }

    private function makeProvider(string $name): Provider
    {
        return Provider::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'type' => 'ganadero',
            'status' => 'active',
        ]);
    }

    public function test_prepares_purchase_draft_matching_provider_without_creating_purchase(): void
    {
        $provider = $this->makeProvider('Proveedor San Juan');

        $params = $this->tool()->validate($this->adminEmpresa, [
            'provider_name' => 'Proveedor San Juan',
            'invoice_number' => 'A-123',
            'purchased_at' => null,
            'notes' => null,
            'branch_name' => $this->branch->name,
            'items' => [
                ['concept' => 'Carne de cerdo', 'quantity' => 50, 'unit' => 'kg', 'unit_price' => 90],
            ],
        ]);

        $result = $this->tool()->prepareDraft($this->adminEmpresa, $params, $this->context($this->adminEmpresa));

        $this->assertSame('assistant_draft', $result->kind);
        $this->assertSame(0, Purchase::count(), 'La tool no debe crear la compra.');
        $this->assertSame(1, AssistantDraft::count());

        $draft = AssistantDraft::first();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertSame($provider->id, $draft->payload['provider_id']);
        $this->assertTrue($draft->payload['provider_matched']);
        $this->assertEqualsWithDelta(4500.0, $draft->payload['total'], 0.001);
        $this->assertEqualsWithDelta(4500.0, $draft->payload['items'][0]['subtotal'], 0.001);
        $this->assertSame($this->branch->id, $draft->payload['branch_id']);
    }

    public function test_unmatched_provider_is_flagged_missing_and_not_invented(): void
    {
        $params = $this->tool()->validate($this->adminEmpresa, [
            'provider_name' => 'Proveedor Que No Existe',
            'invoice_number' => null,
            'purchased_at' => null,
            'notes' => null,
            'branch_name' => $this->branch->name,
            'items' => [
                ['concept' => 'Pollo', 'quantity' => 10, 'unit' => 'kg', 'unit_price' => 40],
            ],
        ]);

        $this->assertNull($params['provider_id']);

        $this->tool()->prepareDraft($this->adminEmpresa, $params, $this->context($this->adminEmpresa));

        $draft = AssistantDraft::first();
        $this->assertNull($draft->payload['provider_id']);
        $this->assertContains('proveedor', $draft->payload['campos_faltantes']);
        $this->assertNotEmpty($draft->payload['alertas']);
    }

    public function test_admin_sucursal_branch_is_forced(): void
    {
        $this->makeProvider('Proveedor X');

        $params = $this->tool()->validate($this->adminSucursal, [
            'provider_name' => 'Proveedor X',
            'invoice_number' => null,
            'purchased_at' => null,
            'notes' => null,
            'branch_name' => $this->secondBranch->name,
            'items' => [
                ['concept' => 'Res', 'quantity' => 5, 'unit' => 'kg', 'unit_price' => 100],
            ],
        ]);

        $this->assertSame($this->branch->id, $params['branch_id']);
    }

    public function test_tool_is_write_and_prepare_only(): void
    {
        $tool = $this->tool();
        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);
    }

    public function test_extracts_purchase_from_invoice_image(): void
    {
        config()->set('ai.openai.api_key', 'sk-test');
        Storage::fake('local');
        $provider = $this->makeProvider('Carnes Don Pedro');

        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proveedor' => ['id' => $provider->id, 'nombre' => 'Carnes Don Pedro'],
                            'invoice_number' => 'F-4521',
                            'purchased_at' => now()->toDateString(),
                            'lineas' => [
                                ['concepto' => 'Pulpa de res', 'quantity' => 25.5, 'unit' => 'kg', 'unit_price' => 185],
                            ],
                            'total' => 4717.50,
                            'confianza' => 'alta',
                            'alertas' => [],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $image = UploadedFile::fake()->image('factura.jpg');
        $context = $this->context($this->adminEmpresa, [$image]);

        // La factura llega como imagen; el modelo no llenó parámetros de texto.
        $params = $this->tool()->validate($this->adminEmpresa, [
            'provider_name' => null, 'invoice_number' => null, 'purchased_at' => null,
            'notes' => null, 'branch_name' => null, 'items' => [],
        ]);
        $this->tool()->prepareDraft($this->adminEmpresa, $params, $context);

        $this->assertSame(0, Purchase::count());
        $draft = AssistantDraft::first();
        $this->assertSame($provider->id, $draft->payload['provider_id']);
        $this->assertTrue($draft->payload['provider_matched']);
        $this->assertSame('F-4521', $draft->payload['invoice_number']);
        $this->assertCount(1, $draft->payload['items']);
        $this->assertSame('Pulpa de res', $draft->payload['items'][0]['concept']);
        $this->assertEqualsWithDelta(4717.50, $draft->payload['total'], 0.01);
        $this->assertNotEmpty($draft->attachment_paths, 'La factura debe quedar adjunta al borrador.');
    }
}
