<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Enums\SaleStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantCustomerPaymentConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Pérez',
            'status' => 'active',
        ]);
    }

    private function openShift(User $user, ?int $branchId = null): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'user_id' => $user->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 0,
        ]);
    }

    private function pendingSale(float $total, string $createdAt, ?Customer $customer = null): Sale
    {
        $customer ??= $this->customer;

        return $this->makeCompletedSale([
            'customer_id' => $customer->id,
            'branch_id' => $customer->branch_id,
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'status' => SaleStatus::Active->value,
            'completed_at' => null,
            'created_at' => $createdAt,
        ]);
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
            'content' => 'cobra a juan',
        ]);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::CustomerGlobalPayment->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['amount' => 1500],
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'amount_received' => 1500,
            'method' => 'cash',
            'notes' => null,
        ], $over);
    }

    private function confirmUrl(AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_registers_fifo_payment_across_sales(): void
    {
        $old = $this->pendingSale(1000, '2026-06-01 10:00:00');
        $new = $this->pendingSale(800, '2026-06-15 10:00:00');
        $this->openShift($this->adminSucursal);
        $draft = $this->makeDraft($this->adminSucursal);

        $response = $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertOk();

        $cp = CustomerPayment::firstOrFail();
        $this->assertEqualsWithDelta(1500.0, (float) $cp->amount_applied, 0.001);
        $this->assertSame(2, $cp->sales_affected_count);
        $this->assertSame($this->adminSucursal->id, $cp->user_id);

        $children = Payment::where('customer_payment_id', $cp->id)->orderBy('id')->get();
        $this->assertCount(2, $children);
        $this->assertEqualsWithDelta(1000.0, (float) $children[0]->amount, 0.001);
        $this->assertSame($old->id, $children[0]->sale_id);
        $this->assertEqualsWithDelta(500.0, (float) $children[1]->amount, 0.001);
        $this->assertSame($new->id, $children[1]->sale_id);

        $this->assertEqualsWithDelta(0.0, (float) $old->fresh()->amount_pending, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $new->fresh()->amount_pending, 0.001);

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($cp->id, $draft->result_id);

        $this->assertStringContainsString($cp->folio, $response->json('message'));
    }

    public function test_confirm_requires_open_shift(): void
    {
        $this->pendingSale(1000, '2026-06-01 10:00:00');
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_customer_must_belong_to_shift_branch(): void
    {
        // admin-empresa con turno en la sucursal 2, cliente de la sucursal 1.
        $this->pendingSale(1000, '2026-06-01 10:00:00');
        $this->openShift($this->adminEmpresa, $this->secondBranch->id);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_non_cash_overpayment_is_rejected_and_draft_stays_ready(): void
    {
        $this->pendingSale(200, '2026-06-01 10:00:00');
        $this->openShift($this->adminSucursal);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload(['method' => 'transfer', 'amount_received' => 500]))
            ->assertStatus(422);

        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_method_disabled_in_branch_is_rejected(): void
    {
        $this->branch->update(['payment_methods_enabled' => ['cash']]);
        $this->pendingSale(1000, '2026-06-01 10:00:00');
        $this->openShift($this->adminSucursal);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload(['method' => 'card', 'amount_received' => 300]))
            ->assertStatus(422);

        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_draft_is_single_use(): void
    {
        $this->pendingSale(2000, '2026-06-01 10:00:00');
        $this->openShift($this->adminSucursal);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertOk();

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload())
            ->assertStatus(409);

        $this->assertSame(1, CustomerPayment::count());
    }

    public function test_admin_sucursal_cannot_target_other_branch_customer(): void
    {
        $other = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'name' => 'Cliente Ajeno',
            'status' => 'active',
        ]);
        $this->pendingSale(500, '2026-06-01 10:00:00', $other);
        $this->openShift($this->adminSucursal);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), $this->payload(['customer_id' => $other->id]))
            ->assertStatus(422);

        $this->assertSame(0, CustomerPayment::count());
    }
}
