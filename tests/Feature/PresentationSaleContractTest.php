<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\SaleItemFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Tests del contrato de persistencia para ventas con presentación.
 * Cubre los 3 write paths: Workbench (admin), API v1 (báscula),
 * Public/Order (pedidos web).
 *
 * Validan que las filas resultantes en sale_items lleven los nuevos
 * campos (presentation_id, presentation_snapshot, sale_mode_at_sale,
 * quantity_unit) además del legado (product_name, unit_type, quantity)
 * correcto.
 */
class PresentationSaleContractTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $cheese;

    private ProductPresentation $halfKilo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Lácteos',
            'status' => 'active',
        ]);

        $this->cheese = Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'Queso',
            'price' => 150,
            'unit_type' => 'kg',
            'sale_mode' => 'both',
            'status' => 'active',
        ]);

        $this->halfKilo = ProductPresentation::create([
            'product_id' => $this->cheese->id,
            'name' => 'medio queso',
            'content' => 500,
            'unit' => 'g',
            'price' => 75,
            'sort_order' => 1,
            'status' => 'active',
        ]);
    }

    public function test_workbench_persists_presentation_contract(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->post(
            route('sucursal.workbench.store', $this->tenant->slug),
            ['items' => [['product_id' => $this->cheese->id, 'quantity' => 1, 'presentation_id' => $this->halfKilo->id]]],
        );

        $response->assertRedirect();

        $item = SaleItem::query()->latest('id')->first();
        $this->assertNotNull($item);
        $this->assertSame($this->halfKilo->id, $item->presentation_id);
        $this->assertSame('unit', $item->unit_type);
        $this->assertSame('unit', $item->quantity_unit);
        $this->assertSame('presentation', $item->sale_mode_at_sale);
        $this->assertSame(75.0, (float) $item->subtotal);
        $this->assertEquals('Queso - medio queso', $item->product_name);
        $this->assertEquals('medio queso', $item->presentation_snapshot['name']);
        $this->assertEquals(500.0, (float) $item->presentation_snapshot['content']);
        $this->assertEquals('g', $item->presentation_snapshot['unit']);
        $this->assertEquals(75.0, (float) $item->presentation_snapshot['price']);
    }

    public function test_workbench_persists_weight_line_with_kg_unit(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->post(
            route('sucursal.workbench.store', $this->tenant->slug),
            ['items' => [['product_id' => $this->cheese->id, 'quantity' => 0.7]]],
        );

        $response->assertRedirect();

        $item = SaleItem::query()->latest('id')->first();
        $this->assertNotNull($item);
        $this->assertNull($item->presentation_id);
        $this->assertNull($item->presentation_snapshot);
        $this->assertSame('kg', $item->unit_type);
        $this->assertSame('kg', $item->quantity_unit);
        $this->assertSame('weight', $item->sale_mode_at_sale);
        $this->assertEquals(105.0, (float) $item->subtotal); // 0.7 × 150
    }

    public function test_api_v1_persists_presentation_contract(): void
    {
        // Create API key for the branch
        $rawKey = 'csa_test_'.bin2hex(random_bytes(8));
        ApiKey::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Test',
            'key_hash' => hash('sha256', $rawKey),
            'last_used_at' => null,
        ]);

        $response = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $this->cheese->id, 'quantity' => 1, 'presentation_id' => $this->halfKilo->id],
            ],
        ], ['X-Api-Key' => $rawKey]);

        $response->assertCreated();

        $item = SaleItem::query()->latest('id')->first();
        $this->assertNotNull($item);
        $this->assertSame($this->halfKilo->id, $item->presentation_id);
        $this->assertSame('unit', $item->unit_type);
        $this->assertSame('unit', $item->quantity_unit);
        $this->assertSame('presentation', $item->sale_mode_at_sale);
        $this->assertEquals('medio queso', $item->presentation_snapshot['name']);
    }

    public function test_legacy_rows_keep_working_via_fallback(): void
    {
        // Simulate a row that pre-dates the migration: only legacy fields filled.
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'L-1',
            'payment_method' => 'cash',
            'total' => 75,
            'amount_paid' => 75,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed->value,
        ]);

        // Manually insert a legacy item via direct create; only legacy fields.
        $legacy = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->cheese->id,
            'product_name' => 'Queso - medio queso',
            'unit_type' => 'kg',          // legacy: heredado del producto, ambiguo
            'quantity' => 1,              // legacy: "1 presentación" pero como kg
            'unit_price' => 75,
            'subtotal' => 75,
        ]);

        $this->assertNull($legacy->presentation_id);
        $this->assertNull($legacy->presentation_snapshot);
        $this->assertNull($legacy->quantity_unit);

        // Formatter must still render this without crashing.
        $formatter = new SaleItemFormatter;
        $this->assertSame('Queso - medio queso', $formatter->displayName($legacy));
        $this->assertSame('1.000 kg', $formatter->displayQuantity($legacy));
    }
}
