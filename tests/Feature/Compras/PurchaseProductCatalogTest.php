<?php

namespace Tests\Feature\Compras;

use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductCategory;
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
        $cat = PurchaseProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Res', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.productos-compra.store', $this->tenant->slug), [
            'name' => 'Costilla de res',
            'unit' => 'kg',
            'purchase_product_category_id' => $cat->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('purchase_products', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Costilla de res',
            'purchase_product_category_id' => $cat->id,
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

    public function test_create_logs_audit_event(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.productos-compra.store', $this->tenant->slug), [
            'name' => 'Costilla de res', 'unit' => 'kg', 'category' => 'res',
        ])->assertRedirect();

        $product = PurchaseProduct::firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'event' => 'created',
            'user_id' => $this->adminEmpresa->id,
        ]);
    }

    public function test_update_logs_audit_diff(): void
    {
        $cat = PurchaseProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Res', 'status' => 'active']);
        $product = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Canal de res',
            'unit' => 'kg', 'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->put(route('empresa.productos-compra.update', [$this->tenant->slug, $product->id]), [
            'name' => 'Canal de res', 'unit' => 'kg', 'purchase_product_category_id' => $cat->id, 'status' => 'inactive',
        ])->assertRedirect();

        $log = AuditLog::where('auditable_id', $product->id)->where('event', 'updated')->firstOrFail();
        $this->assertSame($this->adminEmpresa->id, $log->user_id);
        $this->assertSame([null, 'Res'], $log->changes['fields']['category']);
        $this->assertSame(['Activo', 'Inactivo'], $log->changes['fields']['status']);
    }

    public function test_update_without_changes_does_not_log(): void
    {
        $product = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Canal de res',
            'unit' => 'kg', 'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->put(route('empresa.productos-compra.update', [$this->tenant->slug, $product->id]), [
            'name' => 'Canal de res', 'unit' => 'kg', 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame(0, AuditLog::where('event', 'updated')->count());
    }

    public function test_history_endpoint_returns_entries(): void
    {
        $product = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Canal de res',
            'unit' => 'kg', 'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->put(route('empresa.productos-compra.update', [$this->tenant->slug, $product->id]), [
            'name' => 'Canal de res', 'unit' => 'kg', 'status' => 'inactive',
        ])->assertRedirect();

        $this->get(route('empresa.productos-compra.historial', [$this->tenant->slug, $product->id]))
            ->assertOk()
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('history.0.event', 'updated')
            ->assertJsonPath('history.0.user_name', $this->adminEmpresa->name);
    }

    public function test_index_includes_stats(): void
    {
        $cat = PurchaseProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Res', 'status' => 'active']);
        PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'unit' => 'kg', 'status' => 'active', 'purchase_product_category_id' => $cat->id]);
        PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'unit' => 'kg', 'status' => 'inactive']);
        PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'unit' => 'kg', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $stats = $this->get(route('empresa.productos-compra.index', $this->tenant->slug))
            ->viewData('page')['props']['stats'];

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['active']);
        $this->assertSame(1, $stats['inactive']);
        $this->assertSame(2, $stats['uncategorized']);
    }

    public function test_index_paginates_products(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            PurchaseProduct::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Prod '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'unit' => 'kg',
                'status' => 'active',
            ]);
        }

        $this->actingAs($this->adminEmpresa);
        $products = $this->get(route('empresa.productos-compra.index', $this->tenant->slug))
            ->viewData('page')['props']['products'];

        $this->assertSame(30, $products['total']);
        $this->assertSame(25, $products['per_page']);
        $this->assertCount(25, $products['data']);
    }

    public function test_admin_empresa_creates_category(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.productos-compra.categorias.store', $this->tenant->slug), [
            'name' => 'Embutidos',
        ])->assertRedirect();

        $this->assertDatabaseHas('purchase_product_categories', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Embutidos',
            'status' => 'active',
        ]);
    }

    public function test_category_name_unique_per_tenant(): void
    {
        PurchaseProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Res', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.productos-compra.index', $this->tenant->slug))
            ->post(route('empresa.productos-compra.categorias.store', $this->tenant->slug), ['name' => 'Res'])
            ->assertSessionHasErrors('name');
    }

    public function test_delete_category_sets_products_uncategorized(): void
    {
        $cat = PurchaseProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Res', 'status' => 'active']);
        $product = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Canal de res',
            'unit' => 'kg', 'status' => 'active', 'purchase_product_category_id' => $cat->id,
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->delete(route('empresa.productos-compra.categorias.destroy', ['tenant' => $this->tenant->slug, 'categoria' => $cat->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('purchase_product_categories', ['id' => $cat->id]);
        $this->assertNull($product->fresh()->purchase_product_category_id);
    }
}
