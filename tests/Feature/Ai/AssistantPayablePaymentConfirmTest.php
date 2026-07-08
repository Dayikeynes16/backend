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

class AssistantPayablePaymentConfirmTest extends TestCase
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

    private function makePurchase(array $attrs = []): Purchase
    {
        return Purchase::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $this->provider->id,
            'folio' => 'CMP-'.uniqid(),
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 4500,
            'total' => 4500,
            'amount_paid' => 0,
            'amount_pending' => 4500,
        ], $attrs));
    }

    private function makeDraft(User $user): AssistantDraft
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
            'content' => 'abona',
        ]);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::PayablePayment->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['amount' => 1000],
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function payload(Purchase $purchase, array $over = []): array
    {
        return array_merge([
            'purchase_id' => $purchase->id,
            'amount' => 1000,
            'payment_method' => 'cash',
            'reference' => null,
            'notes' => null,
            'paid_at' => now()->toDateString(),
        ], $over);
    }

    private function confirmUrl(string $prefix, AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_registers_payment_and_reduces_balance(): void
    {
        $purchase = $this->makePurchase();
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload($purchase))
            ->assertOk();

        $this->assertSame(1, ProviderPayment::count());
        $payment = ProviderPayment::first();
        $this->assertEqualsWithDelta(1000.0, (float) $payment->amount, 0.001);
        $this->assertSame($purchase->id, $payment->purchase_id);
        $this->assertSame($this->adminEmpresa->id, $payment->user_id);

        $purchase->refresh();
        $this->assertEqualsWithDelta(1000.0, (float) $purchase->amount_paid, 0.001);
        $this->assertEqualsWithDelta(3500.0, (float) $purchase->amount_pending, 0.001);

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($payment->id, $draft->result_id);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $purchase->getMorphClass(),
            'auditable_id' => $purchase->id,
            'event' => 'payment_added',
        ]);
    }

    public function test_amount_exceeding_balance_is_rejected(): void
    {
        $purchase = $this->makePurchase();
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload($purchase, ['amount' => 9000]))
            ->assertStatus(422);

        $this->assertSame(0, ProviderPayment::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_credit_method_is_rejected(): void
    {
        $purchase = $this->makePurchase();
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload($purchase, ['payment_method' => 'credit']))
            ->assertStatus(422);

        $this->assertSame(0, ProviderPayment::count());
    }

    public function test_admin_sucursal_cannot_pay_other_branch_purchase(): void
    {
        $purchase = $this->makePurchase(['branch_id' => $this->secondBranch->id]);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload($purchase))
            ->assertStatus(422);

        $this->assertSame(0, ProviderPayment::count());
    }

    public function test_user_cannot_confirm_another_users_draft(): void
    {
        $purchase = $this->makePurchase();
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload($purchase))
            ->assertNotFound();

        $this->assertSame(0, ProviderPayment::count());
    }
}
