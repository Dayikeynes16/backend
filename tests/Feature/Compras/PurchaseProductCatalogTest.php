<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseProductCatalogTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function provider(): Provider
    {
        return Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne']);
    }

    private function payload(array $line): array
    {
        return [
            'provider_id' => $this->provider()->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [$line],
        ];
    }

    public function test_creates_catalog_product_from_line_name(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), $this->payload([
            'concept' => 'Media canal de res',
            'quantity' => 2,
            'unit' => 'kg',
            'unit_price' => 100,
        ]))->assertRedirect();

        $this->assertDatabaseHas('purchase_products', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Media canal de res',
            'unit' => 'kg',
        ]);
        $this->assertDatabaseHas('purchase_items', [
            'concept' => 'Media canal de res',
        ]);
        $this->assertSame(1, PurchaseProduct::count());
    }

    public function test_reuses_existing_catalog_product_by_name_case_insensitive(): void
    {
        $existing = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pierna de cerdo',
            'unit' => 'kg',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), $this->payload([
            'concept' => 'pierna de CERDO',
            'quantity' => 1,
            'unit' => 'kg',
            'unit_price' => 80,
        ]))->assertRedirect();

        $this->assertSame(1, PurchaseProduct::count());
        $this->assertDatabaseHas('purchase_items', [
            'purchase_product_id' => $existing->id,
            'concept' => 'Pierna de cerdo', // snapshot del nombre canónico
        ]);
    }

    public function test_uses_explicit_purchase_product_id(): void
    {
        $pp = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pollo entero',
            'unit' => 'pieza',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), $this->payload([
            'purchase_product_id' => $pp->id,
            'concept' => 'lo que sea',
            'quantity' => 3,
            'unit' => 'pieza',
            'unit_price' => 50,
        ]))->assertRedirect();

        $this->assertDatabaseHas('purchase_items', [
            'purchase_product_id' => $pp->id,
            'concept' => 'Pollo entero', // snapshot, ignora el texto enviado
        ]);
    }

    public function test_admin_empresa_creates_catalog_product(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.productos-compra.store', $this->tenant->slug), [
            'name' => 'Costilla de res',
            'unit' => 'kg',
            'category' => 'res',
        ])->assertRedirect();

        $this->assertDatabaseHas('purchase_products', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Costilla de res',
            'category' => 'res',
        ]);
    }

    public function test_catalog_name_unique_per_tenant(): void
    {
        PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Dup', 'unit' => 'kg', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.productos-compra.index', $this->tenant->slug))
            ->post(route('empresa.productos-compra.store', $this->tenant->slug), ['name' => 'Dup', 'unit' => 'kg'])
            ->assertSessionHasErrors('name');
    }

    public function test_cannot_delete_catalog_product_with_purchases(): void
    {
        $pp = PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Con compras', 'unit' => 'kg', 'status' => 'active']);
        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => $this->provider()->id, 'folio' => 'CMP-2026-00001',
            'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'purchase_product_id' => $pp->id, 'concept' => 'Con compras',
            'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100, 'subtotal' => 100,
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.productos-compra.index', $this->tenant->slug))
            ->delete(route('empresa.productos-compra.destroy', ['tenant' => $this->tenant->slug, 'producto_compra' => $pp->id]))
            ->assertSessionHasErrors('producto');
    }

    public function test_cajero_cannot_access_catalog_crud(): void
    {
        $this->actingAs($this->cajero);
        $this->get(route('empresa.productos-compra.index', $this->tenant->slug))->assertForbidden();
    }
}
