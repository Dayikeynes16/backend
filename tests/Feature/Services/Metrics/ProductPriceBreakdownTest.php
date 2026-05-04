<?php

namespace Tests\Feature\Services\Metrics;

use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\ProductPriceBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProductPriceBreakdownTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ProductPriceBreakdown $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->svc = app(ProductPriceBreakdown::class);
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function build(int $productId, ?array $statuses = null): array
    {
        return $this->svc->build(
            $productId,
            $this->tenant->id,
            $this->branch->id,
            DateRange::preset('this_month'),
            $statuses ?? ['completed', 'pending'],
        );
    }

    public function test_groups_by_unit_price_classifying_catalog_lines(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);

        // Tres ventas a precio catálogo $100.
        for ($i = 0; $i < 3; $i++) {
            $this->makeCompletedSale([], [[
                'product_id' => $p->id,
                'product_name' => 'Carne',
                'quantity' => 1,
                'unit_price' => 100,
                'original_unit_price' => 100,
                'cost_price_at_sale' => 60,
            ]]);
        }

        $out = $this->build($p->id);

        $this->assertCount(1, $out['by_price']);
        $tier = $out['by_price'][0];
        $this->assertSame('catalog', $tier['discount_kind']);
        $this->assertSame(100.0, $tier['unit_price']);
        $this->assertSame(3, $tier['lines']);
        $this->assertSame(300.0, $tier['revenue']);
        $this->assertSame(180.0, $tier['cost']);
        $this->assertSame(120.0, $tier['profit']);
    }

    public function test_separates_discounted_and_preferential_with_same_unit_price(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);

        // Cliente con precio preferencial registrado.
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Pref',
            'phone' => '+5215555555555',
            'status' => 'active',
        ]);
        CustomerProductPrice::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'product_id' => $p->id,
            'price' => 80,
        ]);

        // Una venta a $80 con cliente preferencial.
        $this->makeCompletedSale(['customer_id' => $customer->id], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 1, 'unit_price' => 80, 'original_unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);

        // Otra venta a $80 SIN cliente preferencial → descuento manual.
        $this->makeCompletedSale([], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 1, 'unit_price' => 80, 'original_unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);

        $out = $this->build($p->id);

        // Mismo unit_price ($80) pero clasificación distinta → 2 tiers.
        $this->assertCount(2, $out['by_price']);
        $kinds = array_column($out['by_price'], 'discount_kind');
        $this->assertContains('preferential', $kinds);
        $this->assertContains('discounted', $kinds);
    }

    public function test_attaches_top_customer_only_to_preferential_tiers(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);

        $cust = Customer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'name' => 'VIP', 'phone' => '+5215555550000', 'status' => 'active',
        ]);
        CustomerProductPrice::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $cust->id,
            'product_id' => $p->id, 'price' => 70,
        ]);

        $this->makeCompletedSale(['customer_id' => $cust->id], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 2, 'unit_price' => 70, 'original_unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);
        $this->makeCompletedSale([], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 1, 'unit_price' => 100, 'original_unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);

        $out = $this->build($p->id);

        $preferential = collect($out['by_price'])->firstWhere('discount_kind', 'preferential');
        $catalog = collect($out['by_price'])->firstWhere('discount_kind', 'catalog');

        $this->assertNotNull($preferential['top_customer'] ?? null);
        $this->assertSame('VIP', $preferential['top_customer']['name']);
        $this->assertArrayNotHasKey('top_customer', $catalog);
    }

    public function test_summary_computes_lost_to_discounts(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);

        $this->makeCompletedSale([], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 5, 'unit_price' => 80, 'original_unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);

        $out = $this->build($p->id);

        // 5 unidades a $20 menos del catálogo = $100 perdidos.
        $this->assertSame(100.0, $out['summary']['lost_to_discounts']);
    }

    public function test_by_customer_sorts_by_revenue_and_includes_lowest_price(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);
        $a = Customer::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'name' => 'A', 'phone' => '+5211', 'status' => 'active']);
        $b = Customer::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'name' => 'B', 'phone' => '+5212', 'status' => 'active']);

        $this->makeCompletedSale(['customer_id' => $a->id], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 5, 'unit_price' => 100, 'original_unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);
        $this->makeCompletedSale(['customer_id' => $b->id], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 1, 'unit_price' => 70, 'original_unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);

        $out = $this->build($p->id);

        $this->assertSame('A', $out['by_customer'][0]['customer_name']);
        $this->assertSame(500.0, $out['by_customer'][0]['revenue']);
        $this->assertSame(70.0, $out['by_customer'][1]['lowest_unit_price']);
    }

    public function test_by_sale_type_aggregates_by_mode(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);

        $this->makeCompletedSale([], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 1.5, 'unit_price' => 100, 'cost_price_at_sale' => 60,
            'sale_mode_at_sale' => 'weight', 'quantity_unit' => 'kg',
        ]]);
        $this->makeCompletedSale([], [[
            'product_id' => $p->id, 'product_name' => 'Carne 5k',
            'quantity' => 1, 'unit_price' => 500, 'cost_price_at_sale' => 60,
            'sale_mode_at_sale' => 'presentation', 'quantity_unit' => 'unit',
        ]]);

        $out = $this->build($p->id);

        $this->assertCount(2, $out['by_sale_type']);
        $modes = array_column($out['by_sale_type'], 'mode');
        $this->assertContains('weight', $modes);
        $this->assertContains('presentation', $modes);
    }

    public function test_returns_empty_arrays_when_no_sales(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);

        $out = $this->build($p->id);

        $this->assertSame([], $out['by_price']);
        $this->assertSame([], $out['by_customer']);
        $this->assertSame([], $out['by_sale_type']);
        $this->assertSame(0, $out['summary']['tiers_count']);
        $this->assertSame(0.0, $out['summary']['revenue_total']);
    }

    public function test_respects_status_filter(): void
    {
        $p = $this->makeProduct(['name' => 'Carne', 'price' => 100, 'cost_price' => 60]);

        // Una completed y una cancelled.
        $this->makeCompletedSale([], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 1, 'unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);
        $this->makeCompletedSale([
            'status' => \App\Enums\SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-15 10:00:00',
        ], [[
            'product_id' => $p->id, 'product_name' => 'Carne',
            'quantity' => 1, 'unit_price' => 100, 'cost_price_at_sale' => 60,
        ]]);

        // Default (sin cancelled): 1 línea.
        $out = $this->build($p->id);
        $this->assertSame(1, $out['by_price'][0]['lines']);

        // Incluyendo cancelled: 2 líneas (en el mismo tier).
        $out = $this->build($p->id, ['completed', 'pending', 'cancelled']);
        $this->assertSame(2, $out['by_price'][0]['lines']);
    }
}
