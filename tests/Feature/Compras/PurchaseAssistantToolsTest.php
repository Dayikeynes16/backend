<?php

namespace Tests\Feature\Compras;

use App\Enums\PurchaseStatus;
use App\Models\Provider;
use App\Models\Purchase;
use App\Services\Ai\Assistant\Tools\AccountsPayableTool;
use App\Services\Ai\Assistant\Tools\PurchaseSummaryTool;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseAssistantToolsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Provider $provider;

    protected Provider $provider2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->provider = Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne']);
        $this->provider2 = Provider::create(['name' => 'Granja Sol', 'type' => 'ganadero']);
    }

    // ─── PurchaseSummaryTool ──────────────────────────────────────────────

    public function test_purchase_summary_returns_today_totals_for_admin_empresa(): void
    {
        $this->makePurchase(500, $this->provider->id, ['purchased_at' => now()]);
        $this->makePurchase(300, $this->provider2->id, ['purchased_at' => now()]);

        /** @var PurchaseSummaryTool $tool */
        $tool = app(PurchaseSummaryTool::class);
        $params = $tool->validate($this->adminEmpresa, [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => null,
            'top_limit' => 5,
        ]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertSame('purchase_summary', $result->kind);
        $this->assertEquals(800.0, $result->data['total_amount']);
        $this->assertSame(2, $result->data['count']);
        $this->assertEquals(400.0, $result->data['avg_amount']);
        $this->assertCount(2, $result->data['top_providers']);
        // El de mayor monto va primero.
        $this->assertSame('Don Pedro', $result->data['top_providers'][0]['provider_name']);
    }

    public function test_purchase_summary_excludes_cancelled(): void
    {
        $alive = $this->makePurchase(500, $this->provider->id, ['purchased_at' => now()]);
        $cancelled = $this->makePurchase(800, $this->provider->id, ['purchased_at' => now()]);
        $cancelled->update(['status' => PurchaseStatus::Cancelled, 'cancelled_at' => now()]);

        /** @var PurchaseSummaryTool $tool */
        $tool = app(PurchaseSummaryTool::class);
        $params = $tool->validate($this->adminEmpresa, [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => null,
            'top_limit' => 5,
        ]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertEquals(500.0, $result->data['total_amount']);
        $this->assertSame(1, $result->data['count']);
    }

    public function test_purchase_summary_admin_sucursal_scoped_to_own_branch(): void
    {
        $this->makePurchase(500, $this->provider->id, ['branch_id' => $this->branch->id, 'purchased_at' => now()]);
        $this->makePurchase(9999, $this->provider->id, ['branch_id' => $this->secondBranch->id, 'purchased_at' => now()]);

        /** @var PurchaseSummaryTool $tool */
        $tool = app(PurchaseSummaryTool::class);
        $params = $tool->validate($this->adminSucursal, [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => $this->secondBranch->name, // pide otra — debe ignorarse
            'top_limit' => 5,
        ]);
        $this->assertSame($this->branch->id, $params['branch_id']);
        $result = $tool->execute($this->adminSucursal, $params);

        $this->assertEquals(500.0, $result->data['total_amount']);
        $this->assertSame(1, $result->data['count']);
    }

    // ─── AccountsPayableTool ──────────────────────────────────────────────

    public function test_accounts_payable_returns_total_debt(): void
    {
        // 500 saldo en provider1, 200 saldo en provider2, 0 saldo en otra (saldada).
        $this->makePurchase(500, $this->provider->id, ['purchased_at' => now()]);
        $this->makePurchase(200, $this->provider2->id, ['purchased_at' => now()]);
        $saldada = $this->makePurchase(100, $this->provider->id, ['purchased_at' => now()]);
        $saldada->update(['amount_paid' => 100, 'amount_pending' => 0]);

        /** @var AccountsPayableTool $tool */
        $tool = app(AccountsPayableTool::class);
        $params = $tool->validate($this->adminEmpresa, [
            'branch_name' => null,
            'limit' => 5,
        ]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertSame('accounts_payable', $result->kind);
        $this->assertEquals(700.0, $result->data['total_debt']);
        $this->assertSame(2, $result->data['purchase_count']);
        // Provider con más deuda primero.
        $this->assertSame('Don Pedro', $result->data['top_providers'][0]['provider_name']);
        $this->assertEquals(500.0, $result->data['top_providers'][0]['debt']);
    }

    public function test_accounts_payable_excludes_cancelled(): void
    {
        $this->makePurchase(500, $this->provider->id, ['purchased_at' => now()]);
        $cancelled = $this->makePurchase(800, $this->provider2->id, ['purchased_at' => now()]);
        $cancelled->update(['status' => PurchaseStatus::Cancelled, 'cancelled_at' => now()]);

        /** @var AccountsPayableTool $tool */
        $tool = app(AccountsPayableTool::class);
        $params = $tool->validate($this->adminEmpresa, ['branch_name' => null, 'limit' => 5]);
        $result = $tool->execute($this->adminEmpresa, $params);

        $this->assertEquals(500.0, $result->data['total_debt']);
        $this->assertSame(1, $result->data['purchase_count']);
        $this->assertCount(1, $result->data['top_providers']);
    }

    public function test_accounts_payable_admin_sucursal_scoped(): void
    {
        $this->makePurchase(500, $this->provider->id, ['branch_id' => $this->branch->id]);
        $this->makePurchase(9000, $this->provider->id, ['branch_id' => $this->secondBranch->id]);

        /** @var AccountsPayableTool $tool */
        $tool = app(AccountsPayableTool::class);
        $params = $tool->validate($this->adminSucursal, [
            'branch_name' => $this->secondBranch->name, // pide otra, debe ignorar
            'limit' => 5,
        ]);
        $result = $tool->execute($this->adminSucursal, $params);

        $this->assertEquals(500.0, $result->data['total_debt']);
    }

    public function test_purchase_tools_are_in_registry_for_both_roles(): void
    {
        foreach ([PurchaseSummaryTool::class, AccountsPayableTool::class] as $cls) {
            $tool = app($cls);
            $this->assertContains('admin-empresa', $tool->rolesAllowed(), $cls);
            $this->assertContains('admin-sucursal', $tool->rolesAllowed(), $cls);
        }
    }

    // ─── Dashboard KPIs ───────────────────────────────────────────────────

    public function test_dashboard_includes_purchases_snapshot(): void
    {
        $this->makePurchase(500, $this->provider->id, ['purchased_at' => now()]);
        $this->makePurchase(300, $this->provider->id, ['purchased_at' => now()]);

        $this->actingAs($this->adminEmpresa);
        $this->get(route('empresa.dashboard', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('purchases')
                ->where('purchases.total_today', fn ($v) => (float) $v === 800.0)
                ->where('purchases.count_today', 2)
                ->where('purchases.pending_total', fn ($v) => (float) $v === 800.0)
                ->where('purchases.pending_count', 2)
            );
    }

    // ─── helper ───────────────────────────────────────────────────────────

    private function makePurchase(float $total, int $providerId, array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $providerId,
            'folio' => 'CMP-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'created_by' => $this->adminEmpresa->id,
        ], $overrides));
    }
}
