<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\AiDraftStatus;
use App\Models\AiExpenseDraft;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\ExpenseAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ExpenseSubcategory $subcategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active',
        ]);
        $this->subcategory = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id, 'expense_category_id' => $category->id,
            'name' => 'Luz', 'status' => 'active',
        ]);
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    private function openShift(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
    }

    public function test_store_requires_open_shift(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/hub/expenses', [
                'concept' => 'Recibo CFE', 'amount' => 150, 'expense_subcategory_id' => $this->subcategory->id,
            ])
            ->assertStatus(409);
    }

    public function test_store_creates_cash_expense_tied_to_shift(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/expenses', [
                'concept' => 'Recibo CFE', 'amount' => 150, 'expense_subcategory_id' => $this->subcategory->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.concept', 'Recibo CFE')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.subcategory.name', 'Luz');

        $this->assertSame(1, Expense::where('user_id', $this->cajero->id)->count());
    }

    public function test_index_lists_user_expenses_and_categories(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)->postJson('/api/v1/hub/expenses', [
            'concept' => 'Recibo CFE', 'amount' => 150, 'expense_subcategory_id' => $this->subcategory->id,
        ])->assertCreated();

        $res = $this->withToken($token)->getJson('/api/v1/hub/expenses')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals(150.0, $res->json('total'));
        $this->assertSame('Servicios', $res->json('categories.0.name'));
        $this->assertSame('Luz', $res->json('categories.0.subcategories.0.name'));
    }

    public function test_validation_rejects_subcategory_of_other_tenant(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)
            ->postJson('/api/v1/hub/expenses', [
                'concept' => 'x', 'amount' => 10, 'expense_subcategory_id' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_admin_empresa_forbidden(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/expenses')
            ->assertStatus(403);
    }

    private function createExpense(string $token, string $concept = 'Recibo CFE', float $amount = 150): int
    {
        return $this->withToken($token)
            ->postJson('/api/v1/hub/expenses', [
                'concept' => $concept, 'amount' => $amount, 'expense_subcategory_id' => $this->subcategory->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_index_includes_shift_context(): void
    {
        $token = $this->token();
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 500])->assertCreated();
        $this->createExpense($token);

        $res = $this->withToken($token)->getJson('/api/v1/hub/expenses')->assertOk();
        $this->assertEquals(500, $res->json('shift.opening_amount'));
        $this->assertNotNull($res->json('shift.opened_at'));
    }

    public function test_search_filters_by_concept(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->createExpense($token, 'Recibo de luz');
        $this->createExpense($token, 'Compra de bolsas');

        $res = $this->withToken($token)->getJson('/api/v1/hub/expenses?search=luz')->assertOk();
        $this->assertCount(1, $res->json('data'));
    }

    public function test_update_expense(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createExpense($token);

        $this->withToken($token)
            ->patchJson("/api/v1/hub/expenses/{$id}", [
                'concept' => 'Recibo agua', 'amount' => 99, 'expense_subcategory_id' => $this->subcategory->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.concept', 'Recibo agua')
            ->assertJsonPath('data.amount', 99);
    }

    public function test_cancel_expense_removes_it_from_list(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createExpense($token);

        $this->withToken($token)
            ->deleteJson("/api/v1/hub/expenses/{$id}", ['cancellation_reason' => 'duplicado'])
            ->assertOk()
            ->assertJsonPath('action', 'cancelled');

        $res = $this->withToken($token)->getJson('/api/v1/hub/expenses')->assertOk();
        $this->assertCount(0, $res->json('data'));
        $this->assertEquals(0, $res->json('total'));
    }

    public function test_attach_list_download_and_delete(): void
    {
        Storage::fake(ExpenseAttachmentService::disk());
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createExpense($token);

        // Subir adjunto.
        $res = $this->withToken($token)
            ->post("/api/v1/hub/expenses/{$id}/attachments", [
                'attachments' => [UploadedFile::fake()->image('ticket.jpg')],
            ])
            ->assertOk();
        $this->assertCount(1, $res->json('data.attachments'));
        $attId = $res->json('data.attachments.0.id');

        // Descargar.
        $this->withToken($token)
            ->get("/api/v1/hub/expenses/{$id}/attachments/{$attId}")
            ->assertOk();

        // Borrar.
        $this->withToken($token)
            ->deleteJson("/api/v1/hub/expenses/{$id}/attachments/{$attId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.attachments');
    }

    public function test_attachment_rejects_bad_mime(): void
    {
        Storage::fake(ExpenseAttachmentService::disk());
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createExpense($token);

        $this->withToken($token)
            ->post("/api/v1/hub/expenses/{$id}/attachments", [
                'attachments' => [UploadedFile::fake()->create('notas.txt', 5, 'text/plain')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_cannot_edit_other_users_expense(): void
    {
        $token = $this->token();
        $this->openShift($token);
        // Gasto de otro usuario en la misma sucursal.
        $foreign = Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->subcategory->id, 'user_id' => $this->adminSucursal->id,
            'concept' => 'ajeno', 'amount' => 10, 'payment_method' => 'cash', 'expense_at' => now(),
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/hub/expenses/{$foreign->id}", [
                'concept' => 'x', 'amount' => 5, 'expense_subcategory_id' => $this->subcategory->id,
            ])
            ->assertStatus(404);
    }

    public function test_store_with_ai_draft_id_consumes_draft_and_moves_attachment(): void
    {
        $disk = Storage::fake(ExpenseAttachmentService::disk());
        $token = $this->token();
        $this->openShift($token);

        // Simula un draft Ready de IA con la foto del ticket ya almacenada.
        $srcPath = "tenants/{$this->tenant->id}/ai_drafts/seed/ticket.jpg";
        $disk->put($srcPath, 'fake-image-bytes');
        $draft = AiExpenseDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'status' => AiDraftStatus::Ready->value,
            'input_text' => 'Pagué 150 de luz',
            'attachment_paths' => [[
                'path' => $srcPath,
                'original_name' => 'ticket.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 16,
            ]],
            'parsed_proposal' => ['concepto' => 'Luz', 'monto' => 150],
        ]);

        $res = $this->withToken($token)
            ->postJson('/api/v1/hub/expenses', [
                'concept' => 'Recibo CFE',
                'amount' => 150,
                'expense_subcategory_id' => $this->subcategory->id,
                'ai_draft_id' => $draft->id,
            ])
            ->assertCreated();

        // La foto del draft quedó adjunta al gasto.
        $this->assertCount(1, $res->json('data.attachments'));

        // El draft quedó consumido y ligado al gasto; la foto se movió.
        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed->value, $draft->status->value);
        $this->assertSame($res->json('data.id'), $draft->expense_id);
        $disk->assertMissing($srcPath);
    }

    public function test_ai_draft_endpoint_calls_openai_and_returns_proposal(): void
    {
        Storage::fake(ExpenseAttachmentService::disk());
        config()->set('ai.openai.api_key', 'sk-test');
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'concepto' => 'Recibo de luz',
                        'monto' => 150.0,
                        'fecha' => now()->toDateString(),
                        'expense_subcategory_id' => $this->subcategory->id,
                        'metodo_pago' => 'cash',
                        'descripcion' => 'CFE',
                        'confianza' => 'alta',
                    ])],
                ]],
            ], 200),
        ]);

        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/expenses/ai-draft', [
                'input_text' => 'Pagué 150 de luz a CFE en efectivo',
            ])
            ->assertOk()
            ->assertJsonPath('status', AiDraftStatus::Ready->value)
            ->assertJsonPath('proposal.concepto', 'Recibo de luz');

        $this->assertSame(1, AiExpenseDraft::where('user_id', $this->cajero->id)->count());
    }

    public function test_ai_draft_endpoint_requires_some_input(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/expenses/ai-draft', [])
            ->assertStatus(422);
    }
}
