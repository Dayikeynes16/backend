<?php

namespace Tests\Feature\Compras;

use App\Enums\PurchaseStatus;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProviderPaymentControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->provider = Provider::create(['name' => 'P', 'type' => 'mayorista_carne']);
    }

    // ─── pago a compra ────────────────────────────────────────────────────

    public function test_admin_empresa_applies_partial_payment(): void
    {
        $purchase = $this->makePurchase(1000);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 400,
            'payment_method' => 'cash',
        ])->assertRedirect();

        $fresh = $purchase->fresh();
        $this->assertEquals(400, (float) $fresh->amount_paid);
        $this->assertEquals(600, (float) $fresh->amount_pending);
        $this->assertSame(1, ProviderPayment::count());
    }

    public function test_admin_empresa_applies_total_payment_marks_paid(): void
    {
        $purchase = $this->makePurchase(500);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 500,
            'payment_method' => 'transfer',
            'reference' => 'BBVA-12345',
        ])->assertRedirect();

        $fresh = $purchase->fresh();
        $this->assertEquals(500, (float) $fresh->amount_paid);
        $this->assertEquals(0, (float) $fresh->amount_pending);
    }

    public function test_overpayment_is_rejected_with_422(): void
    {
        $purchase = $this->makePurchase(100);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 150,
            'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, ProviderPayment::count());
        $this->assertEquals(100, (float) $purchase->fresh()->amount_pending);
    }

    public function test_credit_method_is_rejected(): void
    {
        $purchase = $this->makePurchase(100);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 50,
            'payment_method' => 'credit',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_cannot_pay_cancelled_purchase(): void
    {
        $purchase = $this->makePurchase(100);
        $purchase->update(['status' => PurchaseStatus::Cancelled, 'cancelled_at' => now()]);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 50, 'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_cancel_payment_reverts_amount_paid(): void
    {
        $purchase = $this->makePurchase(500);
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 300, 'payment_method' => 'cash',
        ]);
        $pago = ProviderPayment::firstOrFail();
        $this->assertEquals(300, (float) $purchase->fresh()->amount_paid);

        $this->delete(route('empresa.compras.pagos.destroy', [
            'tenant' => $this->tenant->slug, 'compra' => $purchase->id, 'pago' => $pago->id,
        ]), ['reason' => 'cargado equivocado'])->assertRedirect();

        $fresh = $purchase->fresh();
        $this->assertEquals(0, (float) $fresh->amount_paid);
        $this->assertEquals(500, (float) $fresh->amount_pending);
        $this->assertNotNull($pago->fresh()->cancelled_at);
        $this->assertSame('cargado equivocado', $pago->fresh()->cancel_reason);
        $this->assertSame($this->adminEmpresa->id, $pago->fresh()->cancelled_by);
    }

    // ─── pago a cuenta (FIFO) ─────────────────────────────────────────────

    public function test_account_payment_distributes_fifo(): void
    {
        $p1 = $this->makePurchase(300, ['purchased_at' => CarbonImmutable::parse('2026-05-01')]);
        $p2 = $this->makePurchase(200, ['purchased_at' => CarbonImmutable::parse('2026-05-05')]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.proveedores.pagos.store', ['tenant' => $this->tenant->slug, 'provider' => $this->provider->id]), [
            'amount' => 400,
            'payment_method' => 'transfer',
        ])->assertRedirect();

        // P1 debe quedar pagada completa; P2 debe tener 100 abonados.
        $this->assertEquals(0, (float) $p1->fresh()->amount_pending);
        $this->assertEquals(100, (float) $p2->fresh()->amount_pending);
        // 2 pagos creados.
        $this->assertSame(2, ProviderPayment::count());
    }

    public function test_account_payment_creates_credit_payment_when_overshoots(): void
    {
        $p1 = $this->makePurchase(100, ['purchased_at' => now()]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.proveedores.pagos.store', ['tenant' => $this->tenant->slug, 'provider' => $this->provider->id]), [
            'amount' => 250, // sobrante = 150
            'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertEquals(0, (float) $p1->fresh()->amount_pending);
        $this->assertSame(2, ProviderPayment::count());
        $credit = ProviderPayment::whereNull('purchase_id')->firstOrFail();
        $this->assertEquals(150, (float) $credit->amount);
    }

    // ─── Sucursal ─────────────────────────────────────────────────────────

    public function test_admin_sucursal_pays_own_branch_purchase(): void
    {
        $purchase = $this->makePurchase(200, ['branch_id' => $this->branch->id]);

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 200, 'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertEquals(0, (float) $purchase->fresh()->amount_pending);
    }

    public function test_admin_sucursal_cannot_pay_other_branch_purchase(): void
    {
        $foreign = $this->makePurchase(100, ['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $foreign->id]), [
            'amount' => 50, 'payment_method' => 'cash',
        ])->assertStatus(403);

        $this->assertSame(0, ProviderPayment::count());
    }

    public function test_admin_sucursal_account_payment_only_fifo_own_branch(): void
    {
        $own = $this->makePurchase(100, ['branch_id' => $this->branch->id, 'purchased_at' => CarbonImmutable::parse('2026-05-01')]);
        $other = $this->makePurchase(100, ['branch_id' => $this->secondBranch->id, 'purchased_at' => CarbonImmutable::parse('2026-05-02')]);

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.proveedores.pagos.store', ['tenant' => $this->tenant->slug, 'provider' => $this->provider->id]), [
            'amount' => 200,
            'payment_method' => 'transfer',
        ])->assertRedirect();

        // Solo la propia se salda; la otra queda intacta. Sobra 100 que crea
        // un pago "a favor del proveedor" pero ligado a su branch_id.
        $this->assertEquals(0, (float) $own->fresh()->amount_pending);
        $this->assertEquals(100, (float) $other->fresh()->amount_pending);
    }

    public function test_cajero_blocked(): void
    {
        $purchase = $this->makePurchase(100);
        $this->actingAs($this->cajero);
        $this->post(route('empresa.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 50, 'payment_method' => 'cash',
        ])->assertForbidden();
        $this->post(route('sucursal.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'amount' => 50, 'payment_method' => 'cash',
        ])->assertForbidden();
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    private function makePurchase(float $total, array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $this->provider->id,
            'folio' => 'CMP-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => $total,
            'total' => $total,
            'amount_pending' => $total,
            'created_by' => $this->adminEmpresa->id,
        ], $overrides));
    }
}
