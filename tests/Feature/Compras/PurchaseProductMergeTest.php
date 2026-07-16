<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseProductMergeTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function product(string $name, string $unit = 'kg'): PurchaseProduct
    {
        return PurchaseProduct::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'unit' => $unit, 'status' => 'active',
        ]);
    }

    private function lineFor(PurchaseProduct $p, string $concept): void
    {
        $provider = Provider::create(['name' => 'Prov '.uniqid(), 'type' => 'mayorista_carne']);
        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => $provider->id, 'folio' => 'CMP-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id, 'purchase_product_id' => $p->id,
            'concept' => $concept, 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100, 'subtotal' => 100,
        ]);
    }

    public function test_candidates_search_returns_matching_active_products(): void
    {
        $this->product('Canal de res 111');
        $this->product('Canal de res 112');
        $this->product('Pollo entero');

        $this->actingAs($this->adminEmpresa);
        $this->getJson(route('empresa.productos-compra.fusionar.candidatos', [$this->tenant->slug, 'q' => 'canal']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Canal de res 111');
    }

    public function test_preview_returns_impact(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');
        $this->lineFor($a, 'Canal de res 111');

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar.preview', $this->tenant->slug), [
            'canonical_id' => $canonical->id,
            'absorbed_ids' => [$a->id],
        ])->assertOk()
            ->assertJsonPath('absorbed_count', 1)
            ->assertJsonPath('items_count', 1)
            ->assertJsonPath('unit_mismatch', false);
    }

    public function test_merge_executes_and_redirects(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');
        $this->lineFor($a, 'Canal de res 111');

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.productos-compra.index', $this->tenant->slug))
            ->post(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
                'canonical_id' => $canonical->id,
                'absorbed_ids' => [$a->id],
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertSoftDeleted('purchase_products', ['id' => $a->id]);
    }

    public function test_sucursal_cannot_merge(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');

        $this->actingAs($this->adminSucursal);
        $this->post(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$a->id],
        ])->assertForbidden();
    }

    public function test_cajero_cannot_merge(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');

        $this->actingAs($this->cajero);
        $this->post(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$a->id],
        ])->assertForbidden();
    }

    public function test_cannot_merge_product_from_another_tenant(): void
    {
        $canonical = $this->product('Canal de res');

        // Ficha de otro tenant
        $other = \App\Models\Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid(), 'status' => 'active']);
        $foreign = PurchaseProduct::create(['tenant_id' => $other->id, 'name' => 'Ajena', 'unit' => 'kg', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$foreign->id],
        ])->assertStatus(422);

        $this->assertNull($foreign->fresh()->deleted_at);
    }
}
