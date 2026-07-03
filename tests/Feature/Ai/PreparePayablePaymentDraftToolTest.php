<?php

namespace Tests\Feature\Ai;

use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\PreparesDraft;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\Tools\PreparePayablePaymentDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PreparePayablePaymentDraftToolTest extends TestCase
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

    private function tool(): PreparePayablePaymentDraftTool
    {
        return app(PreparePayablePaymentDraftTool::class);
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
            'content' => 'abona al proveedor',
        ]);

        return new ToolContext($session, $msg, []);
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

    private function baseParams(array $over = []): array
    {
        return array_merge([
            'purchase_folio' => null,
            'provider_name' => null,
            'amount' => 1000,
            'payment_method' => 'cash',
            'reference' => null,
            'notes' => null,
            'paid_at' => null,
        ], $over);
    }

    public function test_resolves_target_purchase_by_folio(): void
    {
        $purchase = $this->makePurchase(['folio' => 'CMP-2026-00042']);

        $params = $this->tool()->validate($this->adminEmpresa, $this->baseParams(['purchase_folio' => 'CMP-2026-00042']));

        $this->assertSame($purchase->id, $params['purchase_id']);
        $this->assertEqualsWithDelta(4500.0, $params['purchase']['amount_pending'], 0.001);
    }

    public function test_resolves_single_pending_purchase_by_provider_name(): void
    {
        $purchase = $this->makePurchase();

        $this->tool()->prepareDraft(
            $this->adminEmpresa,
            $this->tool()->validate($this->adminEmpresa, $this->baseParams(['provider_name' => 'Proveedor San Juan'])),
            $this->context($this->adminEmpresa),
        );

        $this->assertSame(0, ProviderPayment::count(), 'La tool no debe registrar el pago.');
        $draft = AssistantDraft::first();
        $this->assertSame($purchase->id, $draft->payload['purchase_id']);
    }

    public function test_multiple_pending_purchases_leave_target_unresolved_with_candidates(): void
    {
        $this->makePurchase(['folio' => 'CMP-A']);
        $this->makePurchase(['folio' => 'CMP-B']);

        $params = $this->tool()->validate($this->adminEmpresa, $this->baseParams(['provider_name' => 'Proveedor San Juan']));

        $this->assertNull($params['purchase_id']);
        $this->assertCount(2, $params['purchase_candidates']);
    }

    public function test_amount_exceeding_balance_is_flagged(): void
    {
        $this->makePurchase(['folio' => 'CMP-2026-00099']);

        $this->tool()->prepareDraft(
            $this->adminEmpresa,
            $this->tool()->validate($this->adminEmpresa, $this->baseParams(['purchase_folio' => 'CMP-2026-00099', 'amount' => 9000])),
            $this->context($this->adminEmpresa),
        );

        $this->assertNotEmpty(AssistantDraft::first()->payload['alertas']);
    }

    public function test_admin_sucursal_cannot_target_other_branch_purchase(): void
    {
        // Compra en la OTRA sucursal.
        $this->makePurchase(['branch_id' => $this->secondBranch->id]);

        $params = $this->tool()->validate($this->adminSucursal, $this->baseParams(['provider_name' => 'Proveedor San Juan']));

        $this->assertNull($params['purchase_id']);
        $this->assertSame([], $params['purchase_candidates']);
    }

    public function test_tool_is_write_and_prepare_only(): void
    {
        $tool = $this->tool();
        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);
    }
}
