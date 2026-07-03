<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantPurchaseDraftConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->provider = Provider::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Proveedor San Juan',
            'type' => 'ganadero',
            'status' => 'active',
        ]);
    }

    private function makeDraft(User $user, array $attrs = []): AssistantDraft
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
            'content' => 'compré carne',
        ]);

        return AssistantDraft::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::Purchase->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['provider_id' => $this->provider->id, 'total' => 4500],
            'expires_at' => now()->addHours(6),
        ], $attrs));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'provider_id' => $this->provider->id,
            'invoice_number' => 'A-123',
            'purchased_at' => now()->toDateString(),
            'notes' => null,
            'branch_id' => $this->branch->id,
            'items' => [
                ['concept' => 'Carne de cerdo', 'quantity' => 50, 'unit' => 'kg', 'unit_price' => 90],
            ],
        ], $over);
    }

    private function confirmUrl(string $prefix, AssistantDraft $draft): string
    {
        return route("{$prefix}.asistente.drafts.confirm", ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_creates_purchase_with_items_and_seeds_payable(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload())
            ->assertOk();

        $this->assertSame(1, Purchase::count());
        $purchase = Purchase::first();
        $this->assertSame($this->provider->id, $purchase->provider_id);
        $this->assertSame($this->branch->id, $purchase->branch_id);
        $this->assertEqualsWithDelta(4500.0, (float) $purchase->total, 0.001);
        // El saldo pendiente se siembra = total (cuenta por pagar implícita).
        $this->assertEqualsWithDelta(4500.0, (float) $purchase->amount_pending, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $purchase->amount_paid, 0.001);

        $this->assertSame(1, PurchaseItem::count());
        // El insumo se resuelve por nombre (find-or-create).
        $this->assertSame(1, PurchaseProduct::where('name', 'Carne de cerdo')->count());

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($purchase->id, $draft->result_id);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $purchase->getMorphClass(),
            'auditable_id' => $purchase->id,
            'event' => 'created',
        ]);
    }

    public function test_admin_sucursal_branch_is_forced_ignoring_tampered_payload(): void
    {
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload(['branch_id' => $this->secondBranch->id]))
            ->assertOk();

        $this->assertSame($this->branch->id, Purchase::first()->branch_id);
    }

    public function test_invalid_provider_is_rejected(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload(['provider_id' => 999999]))
            ->assertStatus(422);

        $this->assertSame(0, Purchase::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_empty_items_are_rejected(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload(['items' => []]))
            ->assertStatus(422);

        $this->assertSame(0, Purchase::count());
    }

    public function test_user_cannot_confirm_another_users_draft(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload())
            ->assertNotFound();

        $this->assertSame(0, Purchase::count());
    }

    public function test_confirm_moves_invoice_attachment_to_purchase(): void
    {
        Storage::fake('local');
        $path = 'tenants/'.$this->tenant->id.'/assistant_drafts/seeded/factura.jpg';
        Storage::disk('local')->put($path, 'fake bytes');

        $draft = $this->makeDraft($this->adminEmpresa);
        $draft->update(['attachment_paths' => [[
            'path' => $path,
            'original_name' => 'factura.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]]]);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload())
            ->assertOk();

        $purchase = Purchase::first();
        $this->assertSame(1, $purchase->attachments()->count());
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($purchase->attachments()->first()->path);
    }
}
