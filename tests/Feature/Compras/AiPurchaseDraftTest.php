<?php

namespace Tests\Feature\Compras;

use App\Enums\AiDraftStatus;
use App\Models\AiPurchaseDraft;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AiPurchaseDraftTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
        $this->provider = Provider::create(['name' => 'Carnes Don Pedro', 'type' => 'mayorista_carne']);
    }

    public function test_draft_endpoint_returns_parsed_proposal(): void
    {
        Storage::fake('local');
        $product = $this->makeProduct(['name' => 'Pulpa de res']);
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proveedor' => ['id' => $this->provider->id, 'nombre' => 'Carnes Don Pedro'],
                            'invoice_number' => 'F-4521',
                            'purchased_at' => now()->toDateString(),
                            'lineas' => [
                                [
                                    'product_id' => $product->id,
                                    'concepto' => 'Pulpa de res',
                                    'quantity' => 25.5,
                                    'unit' => 'kg',
                                    'unit_price' => 185,
                                    'notas' => null,
                                ],
                            ],
                            'total' => 4717.50,
                            'confianza' => 'alta',
                            'alertas' => [],
                            'sugerencia_nuevo_proveedor' => null,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.compras.ia.store', $this->tenant->slug), [
            'input_text' => 'Compré pulpa a Carnes Don Pedro',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.proveedor.id', $this->provider->id)
            ->assertJsonPath('proposal.invoice_number', 'F-4521')
            ->assertJsonPath('proposal.lineas.0.product_id', $product->id)
            ->assertJsonPath('proposal.lineas.0.quantity', 25.5)
            ->assertJsonPath('proposal.confianza', 'alta');

        $draft = AiPurchaseDraft::firstOrFail();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertSame($this->adminEmpresa->id, $draft->user_id);
    }

    public function test_parser_drops_invented_provider_id(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proveedor' => ['id' => 9999, 'nombre' => 'Inventado'],
                            'lineas' => [
                                ['concepto' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 10],
                            ],
                            'confianza' => 'baja',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.compras.ia.store', $this->tenant->slug), [
            'input_text' => 'algo',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.proveedor.id', null)
            ->assertJsonPath('proposal.proveedor.nombre', 'Inventado');
    }

    public function test_parser_drops_invented_product_id_in_line(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proveedor' => ['id' => $this->provider->id, 'nombre' => 'P'],
                            'lineas' => [
                                ['product_id' => 99999, 'concepto' => 'Algo', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 50],
                            ],
                            'confianza' => 'media',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.compras.ia.store', $this->tenant->slug), [
            'input_text' => 'algo',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.lineas.0.product_id', null)
            ->assertJsonPath('proposal.lineas.0.concepto', 'Algo');
    }

    public function test_parser_flags_total_mismatch_with_alert(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proveedor' => ['id' => $this->provider->id, 'nombre' => 'P'],
                            'lineas' => [
                                ['concepto' => 'A', 'quantity' => 10, 'unit' => 'kg', 'unit_price' => 100],
                            ],
                            'total' => 1200, // ≠ 1000 calculado
                            'confianza' => 'media',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.compras.ia.store', $this->tenant->slug), [
            'input_text' => 'compra',
        ]);

        $response->assertOk();
        $alertas = $response->json('proposal.alertas');
        $this->assertNotEmpty($alertas);
        $this->assertStringContainsString('no cuadra', $alertas[0]);
    }

    public function test_storing_purchase_with_ai_draft_id_consumes_draft_and_moves_files(): void
    {
        Storage::fake('local');
        // Crea un draft "ready" con un archivo en disco.
        $path = 'tenants/'.$this->tenant->id.'/ai_purchase_drafts/seeded/factura.jpg';
        Storage::disk('local')->put($path, 'fake bytes');
        $draft = AiPurchaseDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminEmpresa->id,
            'status' => AiDraftStatus::Ready,
            'input_text' => 'x',
            'attachment_paths' => [[
                'path' => $path,
                'original_name' => 'factura.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 10,
            ]],
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100]],
            'ai_draft_id' => $draft->id,
        ])->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(1, $purchase->attachments()->count());

        // El archivo se movió al directorio definitivo.
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($purchase->attachments()->first()->path);

        // El draft quedó consumido.
        $freshDraft = $draft->fresh();
        $this->assertSame(AiDraftStatus::Consumed, $freshDraft->status);
        $this->assertSame($purchase->id, $freshDraft->purchase_id);
    }

    public function test_cross_tenant_draft_is_ignored(): void
    {
        $other = Tenant::create(['name' => 'Y', 'slug' => 'y', 'status' => 'active']);
        $foreignDraft = AiPurchaseDraft::create([
            'tenant_id' => $other->id,
            'user_id' => $this->adminEmpresa->id,
            'status' => AiDraftStatus::Ready,
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100]],
            'ai_draft_id' => $foreignDraft->id,
        ])->assertRedirect();

        // La compra se creó, pero el draft ajeno NO se consumió.
        $this->assertNotNull(Purchase::first());
        $this->assertSame(AiDraftStatus::Ready, $foreignDraft->fresh()->status);
        $this->assertNull($foreignDraft->fresh()->purchase_id);
    }

    public function test_endpoint_requires_at_least_text_image_or_audio(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.compras.ia.store', $this->tenant->slug), [])
            ->assertStatus(422);
    }

    public function test_cajero_cannot_call_ia_endpoint(): void
    {
        $this->actingAs($this->cajero);
        $this->postJson(route('empresa.compras.ia.store', $this->tenant->slug), [
            'input_text' => 'hola',
        ])->assertForbidden();
        $this->postJson(route('sucursal.compras.ia.store', $this->tenant->slug), [
            'input_text' => 'hola',
        ])->assertForbidden();
    }

    public function test_failure_marks_draft_as_failed_and_returns_502(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'down'], 500),
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.compras.ia.store', $this->tenant->slug), [
            'input_text' => 'algo',
        ])->assertStatus(502);

        $this->assertSame(AiDraftStatus::Failed, AiPurchaseDraft::firstOrFail()->status);
    }
}
