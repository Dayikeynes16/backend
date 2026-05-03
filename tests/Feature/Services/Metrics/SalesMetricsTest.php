<?php

namespace Tests\Feature\Services\Metrics;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\SalesMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    private function current(?DateRange $range = null, ?int $branchId = null): array
    {
        $range ??= DateRange::preset('this_month');
        $branchId ??= $this->branch->id;

        return $this->svc->summary($range, $branchId, $this->tenant->id)['current'];
    }

    public function test_sums_completed_sales_in_range_as_net_sales(): void
    {
        $this->makeCompletedSale(['total' => 100, 'completed_at' => '2026-04-15 10:00:00']);
        $this->makeCompletedSale(['total' => 250, 'completed_at' => '2026-04-16 10:00:00']);
        // Out of range
        $this->makeCompletedSale(['total' => 999, 'completed_at' => '2026-03-01 10:00:00']);

        $r = $this->current();

        $this->assertSame(350.0, $r['net_sales']);
        $this->assertSame(2, $r['ticket_count']);
    }

    public function test_excludes_active_cart_sales_from_gross(): void
    {
        $this->makeCompletedSale(['total' => 100]);
        $this->makeCompletedSale(['total' => 999, 'status' => SaleStatus::Active->value, 'completed_at' => null]);

        $r = $this->current();
        $this->assertSame(100.0, $r['gross_sales']);
        $this->assertSame(1, $r['ticket_count']);
    }

    public function test_includes_credit_sales_in_gross_and_net(): void
    {
        // Credit = Completed but with amount_pending > 0. Per the canonical glossary,
        // this is a delivered sale and counts toward gross/net sales.
        $this->makeCompletedSale(['total' => 100]);
        $this->makeCreditSale(['total' => 200]);
        $this->makeCompletedSale(['total' => 50, 'amount_paid' => 30, 'amount_pending' => 20]);

        $r = $this->current();
        $this->assertSame(350.0, $r['gross_sales']);
        $this->assertSame(350.0, $r['net_sales']);
        $this->assertSame(3, $r['ticket_count']);
    }

    public function test_includes_pending_sales_in_gross_sales(): void
    {
        // Pending = delivered but not yet collected (per SaleStatus enum comments).
        // Uses created_at since completed_at is null.
        Carbon::setTestNow('2026-04-15 10:00:00');
        $this->makeCompletedSale([
            'total' => 120,
            'status' => SaleStatus::Pending->value,
            'completed_at' => null,
        ]);
        Carbon::setTestNow('2026-04-17 10:00:00');

        $r = $this->current();
        $this->assertSame(120.0, $r['gross_sales']);
        $this->assertSame(120.0, $r['net_sales']);
        $this->assertSame(1, $r['ticket_count']);
    }

    public function test_excludes_cancelled_sales_from_gross_sales(): void
    {
        $this->makeCompletedSale(['total' => 100]);
        $this->makeCompletedSale([
            'total' => 999,
            'status' => SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-15 10:00:00',
        ]);

        $r = $this->current();
        $this->assertSame(100.0, $r['gross_sales']);
    }

    public function test_subtracts_cancelled_amount_from_net_sales_when_cancelled_at_in_range(): void
    {
        $this->makeCompletedSale(['total' => 500, 'completed_at' => '2026-04-15 10:00:00']);
        $this->makeCompletedSale([
            'total' => 200,
            'status' => SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-16 10:00:00',
        ]);

        $r = $this->current();
        $this->assertSame(500.0, $r['gross_sales']);
        $this->assertSame(300.0, $r['net_sales']);
        $this->assertSame(1, $r['cancelled_count']);
        $this->assertSame(200.0, $r['cancelled_amount']);
    }

    public function test_filters_by_branch(): void
    {
        $this->makeCompletedSale(['total' => 100, 'branch_id' => $this->branch->id]);
        $this->makeCompletedSale(['total' => 500, 'branch_id' => $this->secondBranch->id]);

        $r1 = $this->current(null, $this->branch->id);
        $r2 = $this->current(null, $this->secondBranch->id);
        $rAll = $this->svc->summary(DateRange::preset('this_month'), null, $this->tenant->id)['current'];

        $this->assertSame(100.0, $r1['net_sales']);
        $this->assertSame(500.0, $r2['net_sales']);
        $this->assertSame(600.0, $rAll['net_sales']);
    }

    public function test_tenant_isolation(): void
    {
        $this->makeCompletedSale(['total' => 100]);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherBranch = Branch::create(['tenant_id' => $other->id, 'name' => 'OB', 'address' => 'x', 'status' => 'active']);
        $otherUser = User::create(['tenant_id' => $other->id, 'branch_id' => $otherBranch->id, 'name' => 'u', 'email' => 'o@t.local', 'password' => bcrypt('x')]);
        Sale::create([
            'tenant_id' => $other->id, 'branch_id' => $otherBranch->id, 'user_id' => $otherUser->id,
            'folio' => 'X1', 'payment_method' => 'cash', 'total' => 500, 'amount_paid' => 500, 'amount_pending' => 0,
            'origin' => 'admin', 'status' => SaleStatus::Completed->value,
            'completed_at' => '2026-04-15 10:00:00',
        ]);

        $r = $this->svc->summary(DateRange::preset('this_month'), null, $this->tenant->id)['current'];
        $this->assertSame(100.0, $r['net_sales']);
    }

    public function test_cancelled_sales_counted_separately(): void
    {
        $this->makeCompletedSale(['total' => 100]);
        $this->makeCompletedSale([
            'total' => 300, 'status' => SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-15 10:00:00', 'completed_at' => null,
        ]);

        $r = $this->current();
        $this->assertSame(100.0, $r['gross_sales']);
        $this->assertSame(1, $r['cancelled_count']);
        $this->assertSame(300.0, $r['cancelled_amount']);
    }

    public function test_summary_includes_previous_period(): void
    {
        $this->makeCompletedSale(['total' => 100, 'completed_at' => '2026-04-16 10:00:00']);
        $this->makeCompletedSale(['total' => 50, 'completed_at' => '2026-04-15 10:00:00']);
        $s = $this->svc->summary(DateRange::preset('today'), $this->branch->id, $this->tenant->id);
        // "today" = 2026-04-17 (no sales); previous = 2026-04-16 (100)
        $this->assertSame(0.0, $s['current']['net_sales']);
        $this->assertSame(100.0, $s['previous']['net_sales']);
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

    public function test_returns_zero_not_null_when_range_is_empty(): void
    {
        // No sales at all.
        $r = $this->current();

        $this->assertSame(0.0, $r['gross_sales']);
        $this->assertSame(0.0, $r['net_sales']);
        $this->assertSame(0.0, $r['collected']);
        $this->assertSame(0, $r['ticket_count']);
        $this->assertSame(0, $r['cancelled_count']);
        $this->assertSame(0.0, $r['cancelled_amount']);
    }

    public function test_returns_null_avg_ticket_when_no_tickets(): void
    {
        // No sales, avg_ticket should be null (UI displays "—").
        $r = $this->current();

        $this->assertNull($r['avg_ticket']);
    }

    public function test_avg_ticket_divides_net_sales_by_ticket_count(): void
    {
        $this->makeCompletedSale(['total' => 100]);
        $this->makeCompletedSale(['total' => 300]);

        $r = $this->current();
        $this->assertSame(200.0, $r['avg_ticket']);  // (100+300) / 2
    }

    public function test_returns_collected_from_payments_table(): void
    {
        $sale = $this->makeCompletedSale(['total' => 300, 'amount_paid' => 300]);
        // Create payments directly to test that source is `payments` table.
        DB::table('payments')->insert([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 200,
            'created_at' => '2026-04-15 10:00:00',
            'updated_at' => '2026-04-15 10:00:00',
        ]);
        DB::table('payments')->insert([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'card',
            'amount' => 100,
            'created_at' => '2026-04-15 10:00:00',
            'updated_at' => '2026-04-15 10:00:00',
        ]);

        $r = $this->current();
        $this->assertSame(300.0, $r['collected']);
    }

    public function test_excludes_soft_deleted_payments_from_collected(): void
    {
        $sale = $this->makeCompletedSale(['total' => 100, 'amount_paid' => 100]);
        DB::table('payments')->insert([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 100,
            'created_at' => '2026-04-15 10:00:00',
            'updated_at' => '2026-04-15 10:00:00',
            'deleted_at' => '2026-04-15 11:00:00',
        ]);

        $r = $this->current();
        $this->assertSame(0.0, $r['collected']);
    }

    public function test_daily_series_zero_fills_days_without_sales(): void
    {
        // Solo 1 venta en abril 15. Rango this_month = abril 1..17 (17 dias).
        $this->makeCompletedSale(['total' => 100, 'completed_at' => '2026-04-15 10:00:00']);

        $series = $this->svc->dailySeries(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);

        $this->assertCount(17, $series, 'Debe haber un punto por cada dia del rango (abril 1..17).');
        $this->assertSame('2026-04-01', $series[0]['day']);
        $this->assertSame('2026-04-17', $series[16]['day']);
        $this->assertSame(0.0, $series[0]['total']);
        $this->assertSame(0, $series[0]['tickets']);
        $this->assertSame(100.0, $series[14]['total']); // dia 15 = indice 14
        $this->assertSame(1, $series[14]['tickets']);
        $this->assertSame(100.0, collect($series)->sum('total'));
    }

    public function test_daily_series_zero_fills_single_day_range(): void
    {
        // Preset today: rango es exactamente 1 dia. Una venta ese dia.
        $this->makeCompletedSale(['total' => 50, 'completed_at' => '2026-04-17 10:00:00']);

        $series = $this->svc->dailySeries(DateRange::preset('today'), $this->branch->id, $this->tenant->id);

        $this->assertCount(1, $series);
        $this->assertSame('2026-04-17', $series[0]['day']);
        $this->assertSame(50.0, $series[0]['total']);
    }

    public function test_daily_series_zero_fills_when_no_sales_in_range(): void
    {
        // Sin ventas. this_month = abril 1..17.
        $series = $this->svc->dailySeries(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);

        $this->assertCount(17, $series);
        $this->assertSame(0.0, collect($series)->sum('total'));
        $this->assertSame(0, collect($series)->sum('tickets'));
    }

    public function test_reconciles_summary_net_sales_with_daily_series_minus_cancelled(): void
    {
        // 2 ventas brutas + 1 cancelada en rango.
        $this->makeCompletedSale(['total' => 100, 'completed_at' => '2026-04-15 10:00:00']);
        $this->makeCompletedSale(['total' => 250, 'completed_at' => '2026-04-16 10:00:00']);
        $this->makeCompletedSale([
            'total' => 50,
            'status' => SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-17 09:00:00',
        ]);

        $range = DateRange::preset('this_month');
        $summary = $this->svc->summary($range, $this->branch->id, $this->tenant->id)['current'];
        $series = $this->svc->dailySeries($range, $this->branch->id, $this->tenant->id);
        $seriesTotal = collect($series)->sum('total');

        $this->assertSame($summary['gross_sales'], (float) $seriesTotal);
        $this->assertSame($summary['net_sales'], $summary['gross_sales'] - $summary['cancelled_amount']);
    }
}
