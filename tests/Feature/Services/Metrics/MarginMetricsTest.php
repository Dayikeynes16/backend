<?php

namespace Tests\Feature\Services\Metrics;

use App\Services\Metrics\DateRange;
use App\Services\Metrics\MarginMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class MarginMetricsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private MarginMetrics $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->svc = app(MarginMetrics::class);
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_computes_gross_profit_from_cost_snapshot(): void
    {
        $p = $this->makeProduct(['cost_price' => 60]);
        $this->makeCompletedSale([], [[
            'product_id' => $p->id, 'product_name' => $p->name,
            'quantity' => 2, 'unit_price' => 100,
        ]]);

        $r = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        // revenue = 200, cost = 120, profit = 80
        $this->assertSame(200.0, $r['revenue']);
        $this->assertSame(120.0, $r['cost']);
        $this->assertSame(80.0, $r['gross_profit']);
        $this->assertSame(40.0, $r['margin_pct']);
    }

    public function test_excludes_items_without_cost(): void
    {
        $pOk = $this->makeProduct(['cost_price' => 50]);
        $pNoCost = $this->makeProduct(['cost_price' => null]);

        $this->makeCompletedSale([], [
            ['product_id' => $pOk->id, 'product_name' => $pOk->name, 'quantity' => 1, 'unit_price' => 100],
            ['product_id' => $pNoCost->id, 'product_name' => $pNoCost->name, 'quantity' => 1, 'unit_price' => 80],
        ]);

        $r = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        // revenue = 180 (both items), cost = 50 (only pOk), profit = 50 from pOk
        $this->assertSame(180.0, $r['revenue']);
        $this->assertSame(50.0, $r['cost']);
        $this->assertSame(50.0, $r['gross_profit']);
        $this->assertSame(1, $r['items_without_cost']);
        $this->assertSame(1, $r['items_with_cost']);
    }

    public function test_handles_zero_revenue(): void
    {
        $r = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $this->assertSame(0.0, $r['revenue']);
        $this->assertSame(0.0, $r['margin_pct']);
    }

    public function test_by_product_returns_per_product_margin(): void
    {
        $p1 = $this->makeProduct(['name' => 'A', 'cost_price' => 30]);
        $p2 = $this->makeProduct(['name' => 'B', 'cost_price' => 20]);
        $this->makeCompletedSale([], [
            ['product_id' => $p1->id, 'product_name' => 'A', 'quantity' => 1, 'unit_price' => 100],
            ['product_id' => $p2->id, 'product_name' => 'B', 'quantity' => 2, 'unit_price' => 50],
        ]);

        $rows = $this->svc->byProduct(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $byName = collect($rows)->pluck('gross_profit', 'product_name')->toArray();
        $this->assertEqualsWithDelta(70.0, $byName['A'], 0.01);
        $this->assertEqualsWithDelta(60.0, $byName['B'], 0.01); // (100-40)
    }
}
