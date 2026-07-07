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
use App\Services\Ai\Assistant\Tools\PrepareProviderAccountPaymentDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PrepareProviderAccountPaymentDraftToolTest extends TestCase
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

    private function tool(): PrepareProviderAccountPaymentDraftTool
    {
        return app(PrepareProviderAccountPaymentDraftTool::class);
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
            'content' => 'págale 5000 a carnes del norte',
        ]);

        return new ToolContext($session, $msg, []);
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

    private function baseParams(array $over = []): array
    {
        return array_merge([
            'provider_name' => null,
            'amount' => 5000,
            'payment_method' => 'transfer',
            'reference' => null,
            'notes' => null,
            'paid_at' => null,
        ], $over);
    }

    public function test_resolves_provider_and_includes_fifo_distribution(): void
    {
        $old = $this->makePurchase(3000, '2026-06-01');
        $new = $this->makePurchase(4000, '2026-06-15');

        $params = $this->tool()->validate($this->adminEmpresa, $this->baseParams(['provider_name' => 'carnes del norte']));
        $this->assertSame($this->provider->id, $params['provider_id']);

        $this->tool()->prepareDraft($this->adminEmpresa, $params, $this->context($this->adminEmpresa));

        $this->assertSame(0, ProviderPayment::count(), 'La tool no debe registrar el pago.');

        $draft = AssistantDraft::first();
        $this->assertSame('provider_account_payment', $draft->type->value);

        $distribution = $draft->payload['distribution'];
        $this->assertEqualsWithDelta(7000.0, $distribution['total_pending'], 0.001);
        $this->assertCount(2, $distribution['purchases']);
        $this->assertSame($old->id, $distribution['purchases'][0]['purchase_id']);
        $this->assertEqualsWithDelta(3000.0, $distribution['purchases'][0]['amount_to_apply'], 0.001);
        $this->assertSame($new->id, $distribution['purchases'][1]['purchase_id']);
        $this->assertEqualsWithDelta(2000.0, $distribution['purchases'][1]['amount_to_apply'], 0.001);
    }

    public function test_surplus_is_flagged(): void
    {
        $this->makePurchase(1000, '2026-06-01');

        $this->tool()->prepareDraft(
            $this->adminEmpresa,
            $this->tool()->validate($this->adminEmpresa, $this->baseParams(['provider_name' => 'Carnes del Norte', 'amount' => 1500])),
            $this->context($this->adminEmpresa),
        );

        $payload = AssistantDraft::first()->payload;
        $this->assertEqualsWithDelta(500.0, $payload['distribution']['surplus'], 0.001);
        $this->assertNotEmpty($payload['alertas']);
    }

    public function test_ambiguous_name_leaves_provider_unresolved_with_candidates(): void
    {
        Provider::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Carnes del Sur',
            'type' => 'mayorista_carne',
            'status' => 'active',
        ]);

        $params = $this->tool()->validate($this->adminEmpresa, $this->baseParams(['provider_name' => 'Carnes']));

        $this->assertNull($params['provider_id']);
        $this->assertCount(2, $params['provider_candidates']);
    }

    public function test_missing_fields_are_reported(): void
    {
        $this->tool()->prepareDraft(
            $this->adminEmpresa,
            $this->tool()->validate($this->adminEmpresa, $this->baseParams(['provider_name' => null, 'amount' => null, 'payment_method' => null])),
            $this->context($this->adminEmpresa),
        );

        $missing = AssistantDraft::first()->payload['campos_faltantes'];
        $this->assertContains('proveedor', $missing);
        $this->assertContains('monto', $missing);
        $this->assertContains('método de pago', $missing);
    }

    public function test_admin_sucursal_distribution_only_covers_own_branch(): void
    {
        $mine = $this->makePurchase(1000, '2026-06-01');
        $this->makePurchase(2000, '2026-06-02', ['branch_id' => $this->secondBranch->id]);

        $this->tool()->prepareDraft(
            $this->adminSucursal,
            $this->tool()->validate($this->adminSucursal, $this->baseParams(['provider_name' => 'Carnes del Norte'])),
            $this->context($this->adminSucursal),
        );

        $distribution = AssistantDraft::first()->payload['distribution'];
        $this->assertEqualsWithDelta(1000.0, $distribution['total_pending'], 0.001);
        $this->assertCount(1, $distribution['purchases']);
        $this->assertSame($mine->id, $distribution['purchases'][0]['purchase_id']);
    }

    public function test_tool_is_write_and_prepare_only(): void
    {
        $tool = $this->tool();
        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);
    }
}
