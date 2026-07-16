<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Tenant;
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
        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid(), 'status' => 'active']);
        $foreign = PurchaseProduct::create(['tenant_id' => $other->id, 'name' => 'Ajena', 'unit' => 'kg', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$foreign->id],
        ])->assertStatus(422);

        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_cannot_merge_with_canonical_from_another_tenant(): void
    {
        $a = $this->product('Canal de res 111');

        // Canónico de otro tenant
        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid(), 'status' => 'active']);
        $foreignCanonical = PurchaseProduct::create(['tenant_id' => $other->id, 'name' => 'Ajena', 'unit' => 'kg', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $foreignCanonical->id, 'absorbed_ids' => [$a->id],
        ])->assertStatus(422);

        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_merge_rejects_absorbed_ids_with_only_canonical(): void
    {
        $canonical = $this->product('Canal de res');

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$canonical->id],
        ])->assertStatus(422)->assertJsonValidationErrors('absorbed_ids');

        $this->assertNull($canonical->fresh()->deleted_at);
    }

    public function test_merge_preview_rejects_absorbed_ids_with_only_canonical(): void
    {
        $canonical = $this->product('Canal de res');

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar.preview', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$canonical->id],
        ])->assertStatus(422)->assertJsonValidationErrors('absorbed_ids');
    }

    public function test_recaptures_purchase_with_merged_away_name(): void
    {
        // Escenario real: los números de res se repiten, así que un nombre
        // absorbido por una fusión anterior vuelve a aparecer en una compra
        // nueva. Antes del fix, el índice único (tenant_id, name) — que
        // incluye soft-deletes — rompía esta captura con un 500.
        $canonical = $this->product('Canal de res');
        $absorbed = $this->product('Canal de res 111');
        $this->lineFor($absorbed, 'Canal de res 111');

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id,
            'absorbed_ids' => [$absorbed->id],
        ])->assertRedirect();
        $this->assertSoftDeleted('purchase_products', ['id' => $absorbed->id]);

        $provider = Provider::create(['name' => 'Prov '.uniqid(), 'type' => 'mayorista_carne']);
        $this->post(route('empresa.compras.store', $this->tenant->slug), [
            'provider_id' => $provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [[
                'concept' => 'Canal de res 111',
                'quantity' => 1,
                'unit' => 'kg',
                'unit_price' => 100,
            ]],
        ])->assertRedirect();

        $fresh = PurchaseProduct::where('name', 'Canal de res 111')->whereNull('deleted_at')->first();
        $this->assertNotNull($fresh);
        $this->assertNotSame($absorbed->id, $fresh->id);
    }
}
