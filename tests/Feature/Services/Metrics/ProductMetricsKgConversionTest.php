<?php

namespace Tests\Feature\Services\Metrics;

use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\ProductMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Cubre la fórmula de quantity_kg / quantity_units para distintos
 * tipos de venta:
 *   - peso variable directo (kg)
 *   - presentación cuyo contenido está en peso (kg/g/l/ml)
 *   - presentación cuyo contenido es pieza
 *   - mezcla
 *
 * Esta fórmula vive en ProductMetrics::quantityKgSql() y la usa
 * tanto summary() como byProductFull().
 */
class ProductMetricsKgConversionTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ProductMetrics $svc;

    private Product $cheese;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->svc = app(ProductMetrics::class);
        Carbon::setTestNow('2026-04-17 12:00:00');

        $this->cheese = $this->makeProduct(['name' => 'Queso', 'price' => 200, 'unit_type' => 'kg']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePaidSaleWith(array $items): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 0,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed->value,
            'completed_at' => Carbon::parse('2026-04-15 10:00:00'),
        ]);

        $total = 0;
        foreach ($items as $i) {
            $subtotal = $i['quantity'] * $i['unit_price'];
            $total += $subtotal;
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $i['product_id'],
                'product_name' => $i['product_name'] ?? 'X',
                'unit_type' => $i['unit_type'],
                'quantity' => $i['quantity'],
                'unit_price' => $i['unit_price'],
                'subtotal' => $subtotal,
                'cost_price_at_sale' => $i['cost_price_at_sale'] ?? null,
                'quantity_unit' => $i['quantity_unit'] ?? $i['unit_type'],
                'sale_mode_at_sale' => $i['sale_mode_at_sale'] ?? null,
                'presentation_snapshot' => $i['presentation_snapshot'] ?? null,
            ]);
        }
        $sale->update(['total' => $total, 'amount_paid' => $total]);

        return $sale;
    }

    private function rangeForApril(): DateRange
    {
        return DateRange::preset('this_month');
    }

    public function test_pure_weight_variable_sums_kg_directly(): void
    {
        // 30 kg de queso en peso variable.
        $this->makePaidSaleWith([[
            'product_id' => $this->cheese->id,
            'product_name' => 'Queso',
            'unit_type' => 'kg',
            'quantity_unit' => 'kg',
            'sale_mode_at_sale' => 'weight',
            'quantity' => 30,
            'unit_price' => 200,
        ]]);

        $rows = $this->svc->byProductFull($this->rangeForApril(), $this->branch->id, $this->tenant->id);
        $row = collect($rows)->firstWhere('product_id', $this->cheese->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(30.0, $row['quantity_kg'], 0.001);
        $this->assertSame(0, $row['quantity_units']);
    }

    public function test_kg_presentations_expand_into_kilos(): void
    {
        // 3 presentaciones de queso de 1 kg c/u.
        $this->makePaidSaleWith([[
            'product_id' => $this->cheese->id,
            'product_name' => 'Queso - 1 kg',
            'unit_type' => 'unit',
            'quantity_unit' => 'unit',
            'sale_mode_at_sale' => 'presentation',
            'quantity' => 3,
            'unit_price' => 200,
            'presentation_snapshot' => [
                'id' => 1, 'name' => '1 kg', 'content' => 1, 'unit' => 'kg', 'price' => 200,
            ],
        ]]);

        $rows = $this->svc->byProductFull($this->rangeForApril(), $this->branch->id, $this->tenant->id);
        $row = collect($rows)->firstWhere('product_id', $this->cheese->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(3.0, $row['quantity_kg'], 0.001, 'Presentaciones de 1kg deben sumar a kg, no a unidades.');
        $this->assertSame(0, $row['quantity_units']);
    }

    public function test_gram_presentations_convert_to_kilos(): void
    {
        // 2 medios quesos de 500 g.
        $this->makePaidSaleWith([[
            'product_id' => $this->cheese->id,
            'product_name' => 'Queso - 500 g',
            'unit_type' => 'unit',
            'quantity_unit' => 'unit',
            'sale_mode_at_sale' => 'presentation',
            'quantity' => 2,
            'unit_price' => 100,
            'presentation_snapshot' => [
                'id' => 2, 'name' => '500 g', 'content' => 500, 'unit' => 'g', 'price' => 100,
            ],
        ]]);

        $rows = $this->svc->byProductFull($this->rangeForApril(), $this->branch->id, $this->tenant->id);
        $row = collect($rows)->firstWhere('product_id', $this->cheese->id);

        $this->assertEqualsWithDelta(1.0, $row['quantity_kg'], 0.001);
        $this->assertSame(0, $row['quantity_units']);
    }

    public function test_pieza_presentations_count_as_units_not_kg(): void
    {
        // 4 paquetes (presentación de 1 pieza, sin peso).
        $this->makePaidSaleWith([[
            'product_id' => $this->cheese->id,
            'product_name' => 'Queso - paquete',
            'unit_type' => 'unit',
            'quantity_unit' => 'unit',
            'sale_mode_at_sale' => 'presentation',
            'quantity' => 4,
            'unit_price' => 80,
            'presentation_snapshot' => [
                'id' => 3, 'name' => 'paquete', 'content' => 1, 'unit' => 'pieza', 'price' => 80,
            ],
        ]]);

        $rows = $this->svc->byProductFull($this->rangeForApril(), $this->branch->id, $this->tenant->id);
        $row = collect($rows)->firstWhere('product_id', $this->cheese->id);

        $this->assertEqualsWithDelta(0.0, $row['quantity_kg'], 0.001);
        $this->assertSame(4, $row['quantity_units']);
    }

    public function test_mixed_case_user_scenario_30kg_plus_3_kilo_presentations(): void
    {
        // El caso exacto que planteó el usuario:
        // 30 kg de queso peso variable + 3 presentaciones de 1 kg.
        $this->makePaidSaleWith([
            [
                'product_id' => $this->cheese->id,
                'product_name' => 'Queso',
                'unit_type' => 'kg',
                'quantity_unit' => 'kg',
                'sale_mode_at_sale' => 'weight',
                'quantity' => 30,
                'unit_price' => 200,
            ],
            [
                'product_id' => $this->cheese->id,
                'product_name' => 'Queso - 1 kg',
                'unit_type' => 'unit',
                'quantity_unit' => 'unit',
                'sale_mode_at_sale' => 'presentation',
                'quantity' => 3,
                'unit_price' => 200,
                'presentation_snapshot' => [
                    'id' => 4, 'name' => '1 kg', 'content' => 1, 'unit' => 'kg', 'price' => 200,
                ],
            ],
        ]);

        $rows = $this->svc->byProductFull($this->rangeForApril(), $this->branch->id, $this->tenant->id);
        $row = collect($rows)->firstWhere('product_id', $this->cheese->id);

        $this->assertEqualsWithDelta(33.0, $row['quantity_kg'], 0.001, 'Caso usuario: 30 kg variable + 3 × 1 kg = 33 kg.');
        $this->assertSame(0, $row['quantity_units']);
    }

    public function test_summary_aggregates_kg_correctly_across_lines(): void
    {
        // Dos productos distintos en la misma venta para verificar el SUM global.
        $bistec = $this->makeProduct(['name' => 'Bistec', 'price' => 180, 'unit_type' => 'kg']);

        $this->makePaidSaleWith([
            [
                'product_id' => $this->cheese->id,
                'product_name' => 'Queso',
                'unit_type' => 'kg',
                'quantity_unit' => 'kg',
                'sale_mode_at_sale' => 'weight',
                'quantity' => 5,
                'unit_price' => 200,
            ],
            [
                'product_id' => $bistec->id,
                'product_name' => 'Bistec',
                'unit_type' => 'kg',
                'quantity_unit' => 'kg',
                'sale_mode_at_sale' => 'weight',
                'quantity' => 2,
                'unit_price' => 180,
            ],
            [
                'product_id' => $this->cheese->id,
                'product_name' => 'Queso - 500 g',
                'unit_type' => 'unit',
                'quantity_unit' => 'unit',
                'sale_mode_at_sale' => 'presentation',
                'quantity' => 4,
                'unit_price' => 100,
                'presentation_snapshot' => [
                    'id' => 5, 'name' => '500 g', 'content' => 500, 'unit' => 'g', 'price' => 100,
                ],
            ],
        ]);

        $summary = $this->svc->summary($this->rangeForApril(), $this->branch->id, $this->tenant->id);

        // 5 + 2 + (4 × 0.5) = 9.0 kg
        $this->assertEqualsWithDelta(9.0, (float) $summary['quantity_kg'], 0.001);
        $this->assertSame(0, (int) $summary['quantity_units']);
    }

    public function test_legacy_rows_without_quantity_unit_use_unit_type_fallback(): void
    {
        // Fila legacy: vendí 4 kg con unit_type='kg', sin quantity_unit
        // (filas creadas antes del PR del contrato de presentaciones).
        $this->makePaidSaleWith([[
            'product_id' => $this->cheese->id,
            'product_name' => 'Queso',
            'unit_type' => 'kg',
            'quantity_unit' => null, // legacy
            'quantity' => 4,
            'unit_price' => 200,
        ]]);

        $rows = $this->svc->byProductFull($this->rangeForApril(), $this->branch->id, $this->tenant->id);
        $row = collect($rows)->firstWhere('product_id', $this->cheese->id);

        // El COALESCE(quantity_unit, unit_type) hace fallback a 'kg'.
        $this->assertEqualsWithDelta(4.0, $row['quantity_kg'], 0.001);
    }
}
