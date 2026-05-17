<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiExpenseDraft;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseDraftControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected ExpenseSubcategory $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');

        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'description' => 'Vehículos y traslados',
            'aliases' => ['Vehículos', 'Combustible'],
            'status' => 'active',
        ]);
        $this->sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Gasolina',
            'description' => 'Carga de combustible',
            'aliases' => ['Diésel'],
            'status' => 'active',
        ]);
    }

    public function test_draft_endpoint_calls_openai_and_returns_parsed_proposal(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'concepto' => 'Gasolina camioneta',
                            'monto' => 850.5,
                            'fecha' => now()->toDateString(),
                            'expense_subcategory_id' => $this->sub->id,
                            'categoria_nombre' => 'Transporte',
                            'subcategoria_nombre' => 'Gasolina',
                            'metodo_pago' => 'cash',
                            'branch_id' => null,
                            'descripcion' => 'Carga en PEMEX',
                            'confianza' => 'alta',
                            'confianza_por_campo' => ['monto' => 'alta'],
                            'campos_faltantes' => [],
                            'alertas' => [],
                            'sugerencia_nueva_categoria' => null,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'input_text' => 'Compré gasolina para la camioneta, 850 pesos en efectivo',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.concepto', 'Gasolina camioneta')
            ->assertJsonPath('proposal.monto', 850.5)
            ->assertJsonPath('proposal.expense_subcategory_id', $this->sub->id)
            ->assertJsonPath('proposal.metodo_pago', 'cash')
            ->assertJsonPath('proposal.confianza', 'alta');

        $draft = AiExpenseDraft::firstOrFail();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertSame($this->adminSucursal->id, $draft->user_id);
        $this->assertSame('openai', $draft->ai_provider);
        $this->assertSame(100, $draft->prompt_tokens);
    }

    public function test_draft_invented_subcategory_id_is_dropped(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'concepto' => 'X',
                        'monto' => 100,
                        'expense_subcategory_id' => 99999, // inexistente
                        'confianza' => 'media',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'input_text' => 'foo',
        ]);

        $response->assertOk()->assertJsonPath('proposal.expense_subcategory_id', null);
    }

    public function test_draft_requires_text_or_image(): void
    {
        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), []);

        $response->assertStatus(422);
    }

    public function test_draft_rejects_pdf_in_f1(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminSucursal);

        $response = $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'input_text' => 'foo',
            'attachments' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ]);

        $response->assertStatus(422);
    }

    public function test_draft_marks_failed_when_openai_errors(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'down'], 500),
        ]);

        $this->actingAs($this->adminSucursal);
        $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'input_text' => 'algo',
        ])->assertStatus(502);

        $draft = AiExpenseDraft::firstOrFail();
        $this->assertSame(AiDraftStatus::Failed, $draft->status);
        $this->assertNotEmpty($draft->error_message);
    }

    public function test_cajero_cannot_use_ia_draft(): void
    {
        $this->actingAs($this->cajero);
        $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'input_text' => 'foo',
        ])->assertForbidden();
    }

    public function test_consuming_draft_moves_files_and_marks_consumed(): void
    {
        Storage::fake('local');

        // Crear un draft "manualmente" como si la IA hubiera respondido.
        $disk = Storage::disk('local');
        $path = "tenants/{$this->tenant->id}/ai_drafts/seed/sample.jpg";
        $disk->put($path, 'fake-binary');
        $disk->assertExists($path);

        $draft = AiExpenseDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'status' => AiDraftStatus::Ready->value,
            'input_text' => 'texto',
            'attachment_paths' => [[
                'path' => $path,
                'original_name' => 'ticket.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 12,
            ]],
            'parsed_proposal' => [],
        ]);

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.gastos.store', $this->tenant->slug), [
            'concept' => 'Gasolina',
            'amount' => 500,
            'expense_subcategory_id' => $this->sub->id,
            'expense_date' => now()->toDateString(),
            'ai_draft_id' => $draft->id,
        ])->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        $this->assertSame(1, $expense->attachments()->count());
        $this->assertSame('ticket.jpg', $expense->attachments()->first()->original_name);

        // Archivo movido fuera del path del draft
        $disk->assertMissing($path);
        $disk->assertExists($expense->attachments()->first()->path);

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($expense->id, $draft->expense_id);
        $this->assertNotNull($draft->consumed_at);
    }

    public function test_audio_is_transcribed_and_combined_with_text(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/audio/transcriptions' => Http::response([
                'text' => 'Compré gasolina para la camioneta, 850 pesos en efectivo',
            ], 200),
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60],
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'concepto' => 'Gasolina',
                        'monto' => 850,
                        'expense_subcategory_id' => $this->sub->id,
                        'metodo_pago' => 'cash',
                        'confianza' => 'alta',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'audio' => UploadedFile::fake()->createWithContent('voice.webm', str_repeat('a', 1024)),
        ]);

        $response->assertOk()
            ->assertJsonPath('audio_transcription', 'Compré gasolina para la camioneta, 850 pesos en efectivo')
            ->assertJsonPath('proposal.expense_subcategory_id', $this->sub->id);

        $draft = AiExpenseDraft::firstOrFail();
        $this->assertNotNull($draft->audio_path);
        $this->assertSame(
            'Compré gasolina para la camioneta, 850 pesos en efectivo',
            $draft->audio_transcription,
        );

        // Asserta que Whisper fue llamado primero y luego chat completions.
        Http::assertSentInOrder([
            fn ($req) => str_contains($req->url(), '/audio/transcriptions'),
            fn ($req) => str_contains($req->url(), '/chat/completions'),
        ]);
    }

    public function test_audio_rejected_when_mime_not_supported(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminSucursal);

        $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'audio' => UploadedFile::fake()->create('virus.exe', 30, 'application/x-msdownload'),
        ])->assertStatus(422);
    }

    public function test_whisper_failure_marks_draft_failed(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/audio/transcriptions' => Http::response(['error' => 'down'], 500),
        ]);

        $this->actingAs($this->adminSucursal);
        $this->postJson(route('sucursal.gastos.ia.store', $this->tenant->slug), [
            'audio' => UploadedFile::fake()->createWithContent('voice.webm', str_repeat('a', 1024)),
        ])->assertStatus(502);

        $draft = AiExpenseDraft::firstOrFail();
        $this->assertSame(AiDraftStatus::Failed, $draft->status);
        $this->assertStringContainsString('Whisper', $draft->error_message);
    }

    public function test_consuming_draft_from_other_tenant_is_ignored(): void
    {
        Storage::fake('local');

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-t', 'status' => 'active']);
        $foreignDraft = AiExpenseDraft::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => null,
            'user_id' => $this->adminSucursal->id,
            'status' => AiDraftStatus::Ready->value,
            'parsed_proposal' => [],
        ]);

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.gastos.store', $this->tenant->slug), [
            'concept' => 'Algo',
            'amount' => 50,
            'expense_subcategory_id' => $this->sub->id,
            'expense_date' => now()->toDateString(),
            'ai_draft_id' => $foreignDraft->id,
        ])->assertSessionHasNoErrors();

        // El gasto se crea sin tocar el draft ajeno
        $this->assertSame(1, Expense::count());
        $foreignDraft->refresh();
        $this->assertSame(AiDraftStatus::Ready, $foreignDraft->status);
    }
}
