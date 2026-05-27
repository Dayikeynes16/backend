<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProviderDetailTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->provider = Provider::create(['name' => 'Carnes Test', 'type' => 'mayorista_carne']);
    }

    // ─── Resumen (KPIs por rango + deuda actual histórica) ────────────────

    public function test_empresa_resumen_aggregates_period_and_all_time_debt(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        // En rango (este mes).
        $a = $this->makePurchase(['purchased_at' => now()->startOfMonth(), 'total' => 10000, 'amount_pending' => 10000]);
        $b = $this->makePurchase(['purchased_at' => now(), 'total' => 15000, 'amount_paid' => 5000, 'amount_pending' => 10000]);
        // Fuera de rango (año pasado) — pero sigue contando para la deuda actual.
        $this->makePurchase(['purchased_at' => now()->subYear(), 'total' => 20000, 'amount_pending' => 20000]);
        // Cancelada — excluida de TODO.
        $this->makePurchase(['purchased_at' => now(), 'status' => 'cancelled', 'total' => 9999, 'amount_pending' => 9999]);

        // Pago vivo en rango + pago cancelado (excluido del total pagado).
        $this->makePayment(['purchase_id' => $b->id, 'amount' => 5000, 'paid_at' => now()]);
        $this->makePayment(['amount' => 1000, 'paid_at' => now(), 'cancelled_at' => now()]);

        $this->actingAs($this->adminEmpresa);
        $res = $this->getJson(route('empresa.proveedores.resumen', [$this->tenant->slug, $this->provider->id, 'from' => $from, 'to' => $to]))
            ->assertOk();

        $this->assertEqualsWithDelta(25000, $res->json('total_comprado'), 0.01); // A + B
        $this->assertSame(2, $res->json('compras_count'));
        $this->assertEqualsWithDelta(5000, $res->json('total_pagado'), 0.01);     // sólo el pago vivo
        $this->assertEqualsWithDelta(40000, $res->json('deuda_actual'), 0.01);    // A + B + año pasado, sin cancelada
        $res->assertJsonPath('ultima_compra.id', $b->id);                          // la más reciente por fecha
    }

    public function test_resumen_is_branch_scoped_for_sucursal_but_tenant_wide_for_empresa(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->makePurchase(['branch_id' => $this->branch->id, 'total' => 10000, 'amount_pending' => 10000]);
        $this->makePurchase(['branch_id' => $this->secondBranch->id, 'total' => 7000, 'amount_pending' => 7000]);
        $this->makePayment(['branch_id' => $this->branch->id, 'amount' => 3000]);
        $this->makePayment(['branch_id' => $this->secondBranch->id, 'amount' => 2000]);

        // Sucursal: sólo su sucursal.
        $this->actingAs($this->adminSucursal);
        $suc = $this->getJson(route('sucursal.proveedores.resumen', [$this->tenant->slug, $this->provider->id, 'from' => $from, 'to' => $to]))
            ->assertOk();
        $this->assertEqualsWithDelta(10000, $suc->json('total_comprado'), 0.01);
        $this->assertEqualsWithDelta(3000, $suc->json('total_pagado'), 0.01);
        $this->assertEqualsWithDelta(10000, $suc->json('deuda_actual'), 0.01);

        // Empresa: todas las sucursales.
        $this->actingAs($this->adminEmpresa);
        $emp = $this->getJson(route('empresa.proveedores.resumen', [$this->tenant->slug, $this->provider->id, 'from' => $from, 'to' => $to]))
            ->assertOk();
        $this->assertEqualsWithDelta(17000, $emp->json('total_comprado'), 0.01);
        $this->assertEqualsWithDelta(5000, $emp->json('total_pagado'), 0.01);
        $this->assertEqualsWithDelta(17000, $emp->json('deuda_actual'), 0.01);
    }

    // ─── Compras / Pagos / Productos ──────────────────────────────────────

    public function test_compras_endpoint_paginates_purchases_in_range(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->makePurchase(['purchased_at' => now()]);
        $this->makePurchase(['purchased_at' => now()]);
        $this->makePurchase(['purchased_at' => now()]);
        $this->makePurchase(['purchased_at' => now()->subYear()]); // fuera de rango

        $this->actingAs($this->adminEmpresa);
        $res = $this->getJson(route('empresa.proveedores.compras', [$this->tenant->slug, $this->provider->id, 'from' => $from, 'to' => $to, 'per_page' => 2]))
            ->assertOk();

        $this->assertSame(3, $res->json('total'));      // 3 en rango
        $this->assertSame(2, $res->json('per_page'));
        $res->assertJsonCount(2, 'data');               // primera página
        $this->assertArrayHasKey('payment_status', $res->json('data.0'));
    }

    public function test_pagos_endpoint_returns_payments_in_range(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $purchase = $this->makePurchase(['amount_pending' => 5000, 'total' => 5000]);
        $this->makePayment(['purchase_id' => $purchase->id, 'amount' => 1500, 'paid_at' => now()]);
        $this->makePayment(['amount' => 800, 'paid_at' => now()]);
        $this->makePayment(['amount' => 400, 'paid_at' => now()->subYear()]); // fuera de rango

        $this->actingAs($this->adminEmpresa);
        $res = $this->getJson(route('empresa.proveedores.pagos.index', [$this->tenant->slug, $this->provider->id, 'from' => $from, 'to' => $to]))
            ->assertOk();

        $this->assertSame(2, $res->json('total'));
        // Uno de los pagos en rango está ligado a la compra; el otro es "a cuenta" (sin compra).
        $folios = collect($res->json('data'))->map(fn ($p) => data_get($p, 'purchase.folio'))->filter()->values();
        $this->assertContains($purchase->folio, $folios->all());
    }

    public function test_productos_endpoint_groups_items_by_product(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $pulpa = PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Pulpa', 'unit' => 'kg']);
        $bistec = PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Bistec', 'unit' => 'kg']);

        $p1 = $this->makePurchase(['purchased_at' => now()]);
        $this->makeItem($p1, $pulpa, ['quantity' => 10, 'subtotal' => 1800]);

        $p2 = $this->makePurchase(['purchased_at' => now()]);
        $this->makeItem($p2, $pulpa, ['quantity' => 5, 'subtotal' => 900]);
        $this->makeItem($p2, $bistec, ['quantity' => 3, 'subtotal' => 600]);

        $this->actingAs($this->adminEmpresa);
        $res = $this->getJson(route('empresa.proveedores.productos', [$this->tenant->slug, $this->provider->id, 'from' => $from, 'to' => $to]))
            ->assertOk();

        $res->assertJsonCount(2, 'items');
        // Ordenado por monto desc: Pulpa primero.
        $res->assertJsonPath('items.0.concept', 'Pulpa');
        $res->assertJsonPath('items.0.times_bought', 2);
        $this->assertEqualsWithDelta(15, $res->json('items.0.total_quantity'), 0.001);
        $this->assertEqualsWithDelta(2700, $res->json('items.0.total_amount'), 0.01);
        $res->assertJsonPath('items.1.concept', 'Bistec');
        $res->assertJsonPath('items.1.times_bought', 1);
    }

    // ─── Aislamiento y seguridad ──────────────────────────────────────────

    public function test_provider_detail_is_tenant_isolated(): void
    {
        $otherTenant = Tenant::create(['name' => 'Otro', 'slug' => 'otro-tenant', 'status' => 'active']);
        $otherProvider = Provider::create(['tenant_id' => $otherTenant->id, 'name' => 'Ajeno', 'type' => 'otro']);

        $this->actingAs($this->adminEmpresa);
        $this->getJson(route('empresa.proveedores.resumen', [$this->tenant->slug, $otherProvider->id]))
            ->assertNotFound();
    }

    public function test_show_renders_inertia_with_seed(): void
    {
        $this->makePurchase(['total' => 4000, 'amount_pending' => 4000]);

        $this->actingAs($this->adminEmpresa);
        $this->get(route('empresa.proveedores.show', [$this->tenant->slug, $this->provider->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Empresa/Proveedores/Show')
                ->where('seed.compras_count', 1)
                ->has('seed.deuda_actual')
                ->has('provider')
            );
    }

    // ─── Helper de estado de pago (fuente única en el modelo) ─────────────

    public function test_purchase_payment_status_helper(): void
    {
        $this->assertSame('pending', $this->makePurchase(['total' => 100, 'amount_paid' => 0])->paymentStatus());
        $this->assertSame('partial', $this->makePurchase(['total' => 100, 'amount_paid' => 40])->paymentStatus());
        $this->assertSame('paid', $this->makePurchase(['total' => 100, 'amount_paid' => 100])->paymentStatus());
        $this->assertSame('cancelled', $this->makePurchase(['status' => 'cancelled', 'total' => 100, 'amount_paid' => 0])->paymentStatus());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function makePurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $this->provider->id,
            'folio' => 'CMP-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'created_by' => $this->adminEmpresa->id,
        ], $overrides));
    }

    private function makePayment(array $overrides = []): ProviderPayment
    {
        return ProviderPayment::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $this->provider->id,
            'paid_at' => now(),
            'amount' => 1000,
            'payment_method' => 'cash',
            'user_id' => $this->adminEmpresa->id,
        ], $overrides));
    }

    private function makeItem(Purchase $purchase, PurchaseProduct $product, array $overrides = []): PurchaseItem
    {
        return PurchaseItem::create(array_merge([
            'purchase_id' => $purchase->id,
            'purchase_product_id' => $product->id,
            'concept' => $product->name,
            'quantity' => 1,
            'unit' => $product->unit,
            'unit_price' => 100,
            'subtotal' => 100,
        ], $overrides));
    }
}
