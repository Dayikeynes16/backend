<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantProviderAccountPaymentConfirmTest extends TestCase
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
            'name' => 'Carnes del Norte',
            'type' => 'mayorista_carne',
            'status' => 'active',
        ]);
    }

    private function makePurchase(float $total, string $purchasedAt, array $attrs = []): Purchase
    {
        return Purchase::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $this->provider->id,
            'folio' => 'CMP-'.uniqid(),
            'purchased_at' => $purchasedAt,
            'status' => 'received',
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
        ], $attrs));
    }

    private function makeDraft(User $user): AssistantDraft
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
            'content' => 'paga al proveedor',
        ]);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::ProviderAccountPayment->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['amount' => 5000],
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'provider_id' => $this->provider->id,
            'amount' => 5000,
            'payment_method' => 'transfer',
            'reference' => null,
            'notes' => null,
            'paid_at' => now()->toDateString(),
        ], $over);
    }

    private function confirmUrl(AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_distributes_fifo_across_purchases(): void
    {
        $old = $this->makePurchase(3000, '2026-06-01');
        $new = $this->makePurchase(4000, '2026-06-15');
        $draft = $this->makeDraft($this->adminEmpresa);

        $response = $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertOk();

        $payments = ProviderPayment::orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertEqualsWithDelta(3000.0, (float) $payments[0]->amount, 0.001);
        $this->assertSame($old->id, $payments[0]->purchase_id);
        $this->assertEqualsWithDelta(2000.0, (float) $payments[1]->amount, 0.001);
        $this->assertSame($new->id, $payments[1]->purchase_id);

        $this->assertEqualsWithDelta(0.0, (float) $old->fresh()->amount_pending, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $new->fresh()->amount_pending, 0.001);

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($payments[0]->id, $draft->result_id);

        $this->assertStringContainsString('Carnes del Norte', $response->json('message'));
    }

    public function test_surplus_creates_orphan_payment_in_favor(): void
    {
        $this->makePurchase(1000, '2026-06-01');
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), $this->payload(['amount' => 1500]))
            ->assertOk();

        $orphan = ProviderPayment::whereNull('purchase_id')->first();
        $this->assertNotNull($orphan);
        $this->assertEqualsWithDelta(500.0, (float) $orphan->amount, 0.001);
        $this->assertStringContainsString('excedente', $orphan->notes);
    }

    public function test_credit_method_is_rejected(): void
    {
        $this->makePurchase(1000, '2026-06-01');
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), $this->payload(['payment_method' => 'credit']))
            ->assertStatus(422);

        $this->assertSame(0, ProviderPayment::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_admin_sucursal_only_pays_own_branch_purchases(): void
    {
        $mine = $this->makePurchase(1000, '2026-06-01');
        $other = $this->makePurchase(2000, '2026-06-02', ['branch_id' => $this->secondBranch->id]);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload(['amount' => 1000]))
            ->assertOk();

        $this->assertEqualsWithDelta(0.0, (float) $mine->fresh()->amount_pending, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $other->fresh()->amount_pending, 0.001);
        $this->assertSame(0, ProviderPayment::whereNull('purchase_id')->count());
    }

    public function test_draft_is_single_use(): void
    {
        $this->makePurchase(9000, '2026-06-01');
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertOk();

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertStatus(409);

        $this->assertSame(1, ProviderPayment::count());
    }

    public function test_user_cannot_confirm_another_users_draft(): void
    {
        $this->makePurchase(1000, '2026-06-01');
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertNotFound();

        $this->assertSame(0, ProviderPayment::count());
    }
}
