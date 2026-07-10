<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\AiDraftStatus;
use App\Models\AiPurchaseDraft;
use App\Models\Provider;
use App\Models\Purchase;
use App\Services\PurchaseAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->provider = Provider::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Proveedor X', 'type' => 'insumos', 'status' => 'active',
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'provider_id' => $this->provider->id,
            'purchased_at' => now()->toDateString(),
            'paid_amount' => 0,
            'items' => [
                ['concept' => 'Costillas', 'quantity' => 10, 'unit' => 'kg', 'unit_price' => 90],
            ],
        ], $over);
    }

    public function test_store_requires_open_shift(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/hub/purchases', $this->payload())
            ->assertStatus(409);
    }

    public function test_store_creates_purchase_with_items_and_folio(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/purchases', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.provider.name', 'Proveedor X')
            ->assertJsonPath('data.total', 900)
            ->assertJsonPath('data.amount_pending', 900);

        $this->assertSame(1, Purchase::where('created_by', $this->cajero->id)->count());
    }

    public function test_store_with_payment_reduces_pending(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/purchases', $this->payload(['paid_amount' => 400]))
            ->assertCreated()
            ->assertJsonPath('data.amount_paid', 400)
            ->assertJsonPath('data.amount_pending', 500);
    }

    public function test_index_lists_purchases_and_providers(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)->postJson('/api/v1/hub/purchases', $this->payload())->assertCreated();

        $res = $this->withToken($token)->getJson('/api/v1/hub/purchases')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Proveedor X', $res->json('providers.0.name'));
    }

    public function test_index_filters_by_date_range(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)->postJson('/api/v1/hub/purchases', $this->payload(['purchased_at' => '2026-06-01']))->assertCreated();
        $this->withToken($token)->postJson('/api/v1/hub/purchases', $this->payload(['purchased_at' => '2026-06-15']))->assertCreated();

        $res = $this->withToken($token)
            ->getJson('/api/v1/hub/purchases?from=2026-06-10&to=2026-06-20')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertStringStartsWith('2026-06-15', (string) $res->json('data.0.purchased_at'));
    }

    public function test_index_admin_sucursal_sees_all_branch_purchases(): void
    {
        // El cajero registra una compra.
        $token = $this->token();
        $this->openShift($token);
        $this->withToken($token)->postJson('/api/v1/hub/purchases', $this->payload())->assertCreated();

        // El cajero solo ve las suyas.
        $own = $this->withToken($token)->getJson('/api/v1/hub/purchases')->assertOk();
        $this->assertCount(1, $own->json('data'));
        $this->assertFalse($own->json('is_admin'));

        // El admin-sucursal ve la compra del cajero (toda la sucursal) y quién la creó.
        // El guard de Sanctum cachea el usuario dentro del mismo test: hay que
        // olvidarlo para que el siguiente request resuelva el token del admin.
        $this->app['auth']->forgetGuards();
        $adminToken = $this->adminSucursal->createToken('hub')->plainTextToken;
        $res = $this->withToken($adminToken)->getJson('/api/v1/hub/purchases')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertTrue($res->json('is_admin'));
        $this->assertNotNull($res->json('data.0.created_by.name'));
    }

    public function test_admin_empresa_forbidden(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/purchases')
            ->assertStatus(403);
    }

    private function createPurchase(string $token, array $over = []): int
    {
        return $this->withToken($token)
            ->postJson('/api/v1/hub/purchases', $this->payload($over))
            ->assertCreated()
            ->json('data.id');
    }

    public function test_show_returns_detail_with_payment_status(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createPurchase($token, ['paid_amount' => 400]);

        $this->withToken($token)
            ->getJson("/api/v1/hub/purchases/{$id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.amount_pending', 500)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonCount(1, 'data.payments');
    }

    public function test_add_payment_then_cancel_payment(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createPurchase($token); // pendiente 900

        $res = $this->withToken($token)
            ->postJson("/api/v1/hub/purchases/{$id}/payments", ['amount' => 900, 'payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_pending', 0);

        $paymentId = collect($res->json('data.payments'))->firstWhere('cancelled_at', null)['id'];

        $this->withToken($token)
            ->deleteJson("/api/v1/hub/purchases/{$id}/payments/{$paymentId}", ['reason' => 'error'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.amount_pending', 900);
    }

    public function test_add_payment_rejects_overpay(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createPurchase($token); // 900

        $this->withToken($token)
            ->postJson("/api/v1/hub/purchases/{$id}/payments", ['amount' => 1000, 'payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_update_replaces_items_and_total(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createPurchase($token);

        $this->withToken($token)
            ->patchJson("/api/v1/hub/purchases/{$id}", $this->payload([
                'items' => [['concept' => 'Lomo', 'quantity' => 5, 'unit' => 'kg', 'unit_price' => 100]],
            ]))
            ->assertOk()
            ->assertJsonPath('data.total', 500);
    }

    public function test_cancel_purchase(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createPurchase($token);

        $this->withToken($token)
            ->postJson("/api/v1/hub/purchases/{$id}/cancel", ['reason' => 'duplicada'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'cancelled');
    }

    public function test_attach_download_and_delete_on_purchase(): void
    {
        Storage::fake(PurchaseAttachmentService::disk());
        $token = $this->token();
        $this->openShift($token);
        $id = $this->createPurchase($token);

        $res = $this->withToken($token)
            ->post("/api/v1/hub/purchases/{$id}/attachments", [
                'attachments' => [UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf')],
            ])
            ->assertOk();
        $this->assertCount(1, $res->json('data.attachments'));
        $attId = $res->json('data.attachments.0.id');

        $this->withToken($token)
            ->get("/api/v1/hub/purchases/{$id}/attachments/{$attId}")
            ->assertOk();

        $this->withToken($token)
            ->deleteJson("/api/v1/hub/purchases/{$id}/attachments/{$attId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.attachments');
    }

    public function test_purchase_products_catalog_search(): void
    {
        $token = $this->token();
        $this->openShift($token);
        // Crear una compra crea el producto de catálogo por nombre.
        $this->createPurchase($token, ['items' => [['concept' => 'Costilla especial', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 50]]]);

        $res = $this->withToken($token)
            ->getJson('/api/v1/hub/purchase-products?search=costilla')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    public function test_store_with_ai_draft_id_consumes_draft_and_moves_attachment(): void
    {
        $disk = Storage::fake(PurchaseAttachmentService::disk());
        $token = $this->token();
        $this->openShift($token);

        $srcPath = "tenants/{$this->tenant->id}/ai_purchase_drafts/seed/factura.jpg";
        $disk->put($srcPath, 'fake-invoice-bytes');
        $draft = AiPurchaseDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'status' => AiDraftStatus::Ready->value,
            'input_text' => 'Compré costillas',
            'attachment_paths' => [[
                'path' => $srcPath,
                'original_name' => 'factura.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 18,
            ]],
            'parsed_proposal' => ['proveedor' => ['id' => $this->provider->id]],
        ]);

        $res = $this->withToken($token)
            ->postJson('/api/v1/hub/purchases', $this->payload(['ai_draft_id' => $draft->id]))
            ->assertCreated();

        $this->assertCount(1, $res->json('data.attachments'));

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed->value, $draft->status->value);
        $this->assertSame($res->json('data.id'), $draft->purchase_id);
        $disk->assertMissing($srcPath);
    }

    public function test_ai_draft_endpoint_calls_openai_and_returns_proposal(): void
    {
        Storage::fake(PurchaseAttachmentService::disk());
        config()->set('ai.openai.api_key', 'sk-test');
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'proveedor' => ['nombre' => 'Proveedor X'],
                        'invoice_number' => 'F-99',
                        'fecha' => now()->toDateString(),
                        'items' => [['concepto' => 'Costillas', 'cantidad' => 10, 'unidad' => 'kg', 'precio' => 90]],
                        'notas' => null,
                    ])],
                ]],
            ], 200),
        ]);

        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/purchases/ai-draft', [
                'input_text' => 'Compré 10 kg de costillas a 90 el kilo, factura F-99',
            ])
            ->assertOk()
            ->assertJsonPath('status', AiDraftStatus::Ready->value)
            ->assertJsonPath('proposal.invoice_number', 'F-99');

        $this->assertSame(1, AiPurchaseDraft::where('user_id', $this->cajero->id)->count());
    }

    public function test_ai_draft_endpoint_requires_some_input(): void
    {
        $token = $this->token();
        $this->openShift($token);

        $this->withToken($token)
            ->postJson('/api/v1/hub/purchases/ai-draft', [])
            ->assertStatus(422);
    }
}
