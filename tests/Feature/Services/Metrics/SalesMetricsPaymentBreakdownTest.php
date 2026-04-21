<?php

namespace Tests\Feature\Services\Metrics;

use App\Services\Metrics\DateRange;
use App\Services\Metrics\SalesMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SalesMetricsPaymentBreakdownTest extends TestCase
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

    private function insertPayment(int $saleId, string $method, float $amount, string $createdAt = '2026-04-15 10:00:00', ?string $deletedAt = null): void
    {
        DB::table('payments')->insert([
            'sale_id' => $saleId,
            'user_id' => $this->cajero->id,
            'method' => $method,
            'amount' => $amount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => $deletedAt,
        ]);
    }

    public function test_groups_payments_by_method_dynamically(): void
    {
        $s1 = $this->makeCompletedSale(['total' => 100]);
        $s2 = $this->makeCompletedSale(['total' => 250]);
        $s3 = $this->makeCompletedSale(['total' => 50]);

        $this->insertPayment($s1->id, 'cash', 100);
        $this->insertPayment($s2->id, 'card', 250);
        $this->insertPayment($s3->id, 'transfer', 50);

        $breakdown = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);

        $this->assertCount(3, $breakdown);
        $byMethod = collect($breakdown)->keyBy('method');
        $this->assertSame(100.0, $byMethod['cash']['total']);
        $this->assertSame(250.0, $byMethod['card']['total']);
        $this->assertSame(50.0, $byMethod['transfer']['total']);
    }

    public function test_includes_count_and_average_per_method(): void
    {
        $s1 = $this->makeCompletedSale(['total' => 300]);
        $this->insertPayment($s1->id, 'cash', 100);
        $this->insertPayment($s1->id, 'cash', 200);

        $breakdown = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $cash = collect($breakdown)->firstWhere('method', 'cash');

        $this->assertSame(300.0, $cash['total']);
        $this->assertSame(2, $cash['count']);
        $this->assertSame(150.0, $cash['average']);
    }

    public function test_orders_methods_by_total_desc(): void
    {
        $s1 = $this->makeCompletedSale(['total' => 500]);
        $this->insertPayment($s1->id, 'cash', 50);
        $this->insertPayment($s1->id, 'card', 300);
        $this->insertPayment($s1->id, 'transfer', 150);

        $breakdown = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $methods = array_map(fn ($r) => $r['method'], $breakdown);

        $this->assertSame(['card', 'transfer', 'cash'], $methods);
    }

    public function test_handles_unknown_method_slug_gracefully(): void
    {
        $s = $this->makeCompletedSale(['total' => 100]);
        $this->insertPayment($s->id, 'vale_despensa', 100);

        $breakdown = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $row = collect($breakdown)->firstWhere('method', 'vale_despensa');

        $this->assertNotNull($row);
        $this->assertSame('Vale Despensa', $row['label']);
        $this->assertSame(100.0, $row['total']);
    }

    public function test_aggregates_split_payments_correctly(): void
    {
        // Una venta pagada con 500 efectivo + 500 tarjeta.
        $s = $this->makeCompletedSale(['total' => 1000]);
        $this->insertPayment($s->id, 'cash', 500);
        $this->insertPayment($s->id, 'card', 500);

        $breakdown = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $byMethod = collect($breakdown)->keyBy('method');

        $this->assertSame(500.0, $byMethod['cash']['total']);
        $this->assertSame(500.0, $byMethod['card']['total']);
    }

    public function test_excludes_soft_deleted_payments_from_breakdown(): void
    {
        $s = $this->makeCompletedSale(['total' => 100]);
        $this->insertPayment($s->id, 'cash', 100, '2026-04-15 10:00:00', '2026-04-15 11:00:00');

        $breakdown = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);

        $this->assertEmpty($breakdown);
    }

    public function test_filters_by_branch(): void
    {
        $s1 = $this->makeCompletedSale(['total' => 100, 'branch_id' => $this->branch->id]);
        $s2 = $this->makeCompletedSale(['total' => 500, 'branch_id' => $this->secondBranch->id]);
        $this->insertPayment($s1->id, 'cash', 100);
        $this->insertPayment($s2->id, 'card', 500);

        $b1 = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $b2 = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->secondBranch->id, $this->tenant->id);

        $this->assertCount(1, $b1);
        $this->assertSame('cash', $b1[0]['method']);
        $this->assertCount(1, $b2);
        $this->assertSame('card', $b2[0]['method']);
    }

    public function test_returns_empty_array_when_no_payments(): void
    {
        $this->makeCompletedSale(['total' => 100]);
        // No payments created.

        $breakdown = $this->svc->byPaymentMethod(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id);
        $this->assertSame([], $breakdown);
    }
}
