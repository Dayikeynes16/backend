<?php

namespace Tests\Feature\Ai;

use App\Enums\SaleStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\PreparesDraft;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\Tools\PrepareCustomerPaymentDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PrepareCustomerPaymentDraftToolTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tool(): PrepareCustomerPaymentDraftTool
    {
        return app(PrepareCustomerPaymentDraftTool::class);
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
            'content' => 'Juan Pérez pagó 1500 en efectivo',
        ]);

        return new ToolContext($session, $msg, []);
    }

    private function makeCustomer(string $name, ?int $branchId = null): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function pendingSale(Customer $customer, float $total, string $createdAt): Sale
    {
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

    private function baseParams(array $over = []): array
    {
        return array_merge([
            'customer_name' => null,
            'amount' => 1500,
            'payment_method' => 'cash',
            'notes' => null,
        ], $over);
    }

    public function test_resolves_customer_by_exact_name_and_includes_distribution(): void
    {
        $juan = $this->makeCustomer('Juan Pérez');
        $this->pendingSale($juan, 1000, '2026-06-01 10:00:00');
        $this->pendingSale($juan, 800, '2026-06-15 10:00:00');

        $params = $this->tool()->validate($this->adminSucursal, $this->baseParams(['customer_name' => 'juan pérez']));
        $this->assertSame($juan->id, $params['customer_id']);

        $this->tool()->prepareDraft($this->adminSucursal, $params, $this->context($this->adminSucursal));

        $this->assertSame(0, CustomerPayment::count(), 'La tool no debe registrar el cobro.');

        $draft = AssistantDraft::first();
        $this->assertSame('customer_global_payment', $draft->type->value);
        $this->assertSame($juan->id, $draft->payload['customer_id']);

        $distribution = $draft->payload['distribution'];
        $this->assertEqualsWithDelta(1800.0, $distribution['total_pending'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $distribution['amount_to_apply'], 0.001);
        $this->assertCount(2, $distribution['sales']);
        $this->assertEqualsWithDelta(1000.0, $distribution['sales'][0]['amount_to_apply'], 0.001);
        $this->assertEqualsWithDelta(500.0, $distribution['sales'][1]['amount_to_apply'], 0.001);
    }

    public function test_resolves_customer_with_accents_and_extra_words(): void
    {
        // Caso real de producción: el usuario dictó "rincón del taco" y el
        // cliente guardado se llama "Rincon" (sin acento, nombre más corto).
        $rincon = $this->makeCustomer('Rincon');
        $this->pendingSale($rincon, 2731, '2026-06-01 10:00:00');

        $params = $this->tool()->validate($this->adminSucursal, $this->baseParams(['customer_name' => 'rincón del taco']));

        $this->assertSame($rincon->id, $params['customer_id']);
    }

    public function test_fuzzy_prefers_strong_match_over_weak_token_hit(): void
    {
        $rincon = $this->makeCustomer('Rincon');
        $this->makeCustomer('Tacos El Güero'); // comparte el token "taco(s)"

        $params = $this->tool()->validate($this->adminSucursal, $this->baseParams(['customer_name' => 'rincón del taco']));

        $this->assertSame($rincon->id, $params['customer_id']);
    }

    public function test_ambiguous_name_leaves_customer_unresolved_with_candidates(): void
    {
        $a = $this->makeCustomer('Juan Pérez');
        $b = $this->makeCustomer('Juan Pereira');
        $this->pendingSale($a, 100, '2026-06-01 10:00:00');
        $this->pendingSale($b, 200, '2026-06-02 10:00:00');

        $params = $this->tool()->validate($this->adminSucursal, $this->baseParams(['customer_name' => 'Juan']));

        $this->assertNull($params['customer_id']);
        $this->assertCount(2, $params['customer_candidates']);
    }

    public function test_missing_fields_are_reported(): void
    {
        $this->tool()->prepareDraft(
            $this->adminSucursal,
            $this->tool()->validate($this->adminSucursal, $this->baseParams(['customer_name' => null, 'amount' => null, 'payment_method' => null])),
            $this->context($this->adminSucursal),
        );

        $missing = AssistantDraft::first()->payload['campos_faltantes'];
        $this->assertContains('cliente', $missing);
        $this->assertContains('monto', $missing);
        $this->assertContains('método de pago', $missing);
    }

    public function test_customer_without_debt_is_flagged(): void
    {
        $this->makeCustomer('Sin Deuda');

        $this->tool()->prepareDraft(
            $this->adminSucursal,
            $this->tool()->validate($this->adminSucursal, $this->baseParams(['customer_name' => 'Sin Deuda'])),
            $this->context($this->adminSucursal),
        );

        $this->assertNotEmpty(AssistantDraft::first()->payload['alertas']);
    }

    public function test_admin_sucursal_cannot_target_other_branch_customer(): void
    {
        $other = $this->makeCustomer('Cliente Ajeno', $this->secondBranch->id);
        $this->pendingSale($other, 500, '2026-06-01 10:00:00');

        $params = $this->tool()->validate($this->adminSucursal, $this->baseParams(['customer_name' => 'Cliente Ajeno']));

        $this->assertNull($params['customer_id']);
        $this->assertSame([], $params['customer_candidates']);
    }

    public function test_tool_is_write_and_prepare_only(): void
    {
        $tool = $this->tool();
        $this->assertFalse($tool->readOnly());
        $this->assertInstanceOf(PreparesDraft::class, $tool);
    }
}
