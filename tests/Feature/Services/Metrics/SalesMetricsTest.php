<?php

namespace Tests\Feature\Services\Metrics;

use App\Enums\SaleStatus;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\SalesMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SalesMetricsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private SalesMetrics $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->svc = app(SalesMetrics::class);
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sums_completed_sales_in_range(): void
    {
        $this->makeCompletedSale(['total' => 100, 'completed_at' => '2026-04-15 10:00:00']);
        $this->makeCompletedSale(['total' => 250, 'completed_at' => '2026-04-16 10:00:00']);
        // Out of range
        $this->makeCompletedSale(['total' => 999, 'completed_at' => '2026-03-01 10:00:00']);

        $range = DateRange::preset('this_month');
        $r = $this->svc->aggregateFor($range, $this->branch->id, $this->tenant->id);

        $this->assertSame(350.0, $r['total_sales']);
        $this->assertSame(2, $r['ticket_count']);
    }

    public function test_only_completed_sales_count(): void
    {
        $this->makeCompletedSale(['total' => 100]);
        $this->makeCompletedSale(['total' => 999, 'status' => SaleStatus::Active->value, 'completed_at' => null]);

        $r = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $this->assertSame(100.0, $r['total_sales']);
        $this->assertSame(1, $r['ticket_count']);
    }

    public function test_filters_by_branch(): void
    {
        $this->makeCompletedSale(['total' => 100, 'branch_id' => $this->branch->id]);
        $this->makeCompletedSale(['total' => 500, 'branch_id' => $this->secondBranch->id]);

        $r1 = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $r2 = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->secondBranch->id, $this->tenant->id);
        $rAll = $this->svc->aggregateFor(DateRange::preset('this_month'), null, $this->tenant->id);

        $this->assertSame(100.0, $r1['total_sales']);
        $this->assertSame(500.0, $r2['total_sales']);
        $this->assertSame(600.0, $rAll['total_sales']);
    }

    public function test_tenant_isolation(): void
    {
        $this->makeCompletedSale(['total' => 100]);

        $other = \App\Models\Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherBranch = \App\Models\Branch::create(['tenant_id' => $other->id, 'name' => 'OB', 'address' => 'x', 'status' => 'active']);
        $otherUser = \App\Models\User::create(['tenant_id' => $other->id, 'branch_id' => $otherBranch->id, 'name' => 'u', 'email' => 'o@t.local', 'password' => bcrypt('x')]);
        \App\Models\Sale::create([
            'tenant_id' => $other->id, 'branch_id' => $otherBranch->id, 'user_id' => $otherUser->id,
            'folio' => 'X1', 'payment_method' => 'cash', 'total' => 500, 'amount_paid' => 500, 'amount_pending' => 0,
            'origin' => 'admin', 'status' => SaleStatus::Completed->value,
            'completed_at' => '2026-04-15 10:00:00',
        ]);

        $r = $this->svc->aggregateFor(DateRange::preset('this_month'), null, $this->tenant->id);
        $this->assertSame(100.0, $r['total_sales']);
    }

    public function test_by_method_splits_payment_types(): void
    {
        $this->makeCompletedSale(['total' => 100, 'payment_method' => 'cash']);
        $this->makeCompletedSale(['total' => 200, 'payment_method' => 'card']);
        $this->makeCompletedSale(['total' => 50, 'payment_method' => 'transfer']);

        $r = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $this->assertSame(100.0, $r['by_method']['cash']);
        $this->assertSame(200.0, $r['by_method']['card']);
        $this->assertSame(50.0, $r['by_method']['transfer']);
    }

    public function test_cancelled_sales_counted_separately(): void
    {
        $this->makeCompletedSale(['total' => 100]);
        $this->makeCompletedSale([
            'total' => 300, 'status' => SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-15 10:00:00', 'completed_at' => null,
        ]);

        $r = $this->svc->aggregateFor(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $this->assertSame(100.0, $r['total_sales']);
        $this->assertSame(1, $r['cancelled_count']);
        $this->assertSame(300.0, $r['cancelled_amount']);
    }

    public function test_summary_includes_previous_period(): void
    {
        $this->makeCompletedSale(['total' => 100, 'completed_at' => '2026-04-16 10:00:00']);
        $this->makeCompletedSale(['total' => 50, 'completed_at' => '2026-04-15 10:00:00']);
        $s = $this->svc->summary(DateRange::preset('today'), $this->branch->id, $this->tenant->id);
        // "today" = 2026-04-17 (no sales); previous = 2026-04-16 (100)
        $this->assertSame(0.0, $s['current']['total_sales']);
        $this->assertSame(100.0, $s['previous']['total_sales']);
    }

    public function test_daily_series_groups_by_day(): void
    {
        $this->makeCompletedSale(['total' => 100, 'completed_at' => '2026-04-15 10:00:00']);
        $this->makeCompletedSale(['total' => 200, 'completed_at' => '2026-04-15 18:00:00']);
        $this->makeCompletedSale(['total' => 50, 'completed_at' => '2026-04-16 10:00:00']);

        $series = $this->svc->dailySeries(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $byDay = collect($series)->pluck('total', 'day')->toArray();
        $this->assertSame(300.0, (float) $byDay['2026-04-15']);
        $this->assertSame(50.0, (float) $byDay['2026-04-16']);
    }
}
