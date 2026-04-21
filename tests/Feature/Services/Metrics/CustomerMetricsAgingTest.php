<?php

namespace Tests\Feature\Services\Metrics;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Services\Metrics\CustomerMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Espejo de CollectionMetricsAgingTest. Misma query, misma semántica,
 * ubicación separada hoy por duplicación preexistente en los servicios.
 * Requires PostgreSQL — run via sail.
 */
class CustomerMetricsAgingTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private CustomerMetrics $svc;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->svc = app(CustomerMetrics::class);
        Carbon::setTestNow('2026-04-17 10:00:00');

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function creditSaleAt(string $completedAt, float $pending): void
    {
        $this->makeCreditSale([
            'total' => $pending,
            'amount_paid' => 0,
            'amount_pending' => $pending,
            'customer_id' => $this->customer->id,
            'completed_at' => $completedAt,
        ]);
    }

    public function test_returns_bucket_keys_0_30_31_60_and_61_plus(): void
    {
        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertArrayHasKey('0-30', $r);
        $this->assertArrayHasKey('31-60', $r);
        $this->assertArrayHasKey('61-plus', $r);
    }

    public function test_buckets_boundaries_exactly(): void
    {
        // 30 días → 0-30
        $this->creditSaleAt('2026-03-18 10:00:00', 10);
        // 31 días → 31-60
        $this->creditSaleAt('2026-03-17 10:00:00', 20);
        // 60 días → 31-60
        $this->creditSaleAt('2026-02-16 10:00:00', 30);
        // 61 días → 61-plus
        $this->creditSaleAt('2026-02-15 10:00:00', 40);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(10.0, $r['0-30']);
        $this->assertSame(50.0, $r['31-60']);
        $this->assertSame(40.0, $r['61-plus']);
    }

    public function test_excludes_cancelled_and_soft_deleted(): void
    {
        // Cancelada
        $this->makeCreditSale([
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'customer_id' => $this->customer->id,
            'status' => SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-15 10:00:00',
        ]);

        // Soft-deleted
        $sale = $this->makeCreditSale([
            'total' => 200,
            'amount_paid' => 0,
            'amount_pending' => 200,
            'customer_id' => $this->customer->id,
        ]);
        $sale->delete();

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(0.0, $r['0-30']);
    }

    public function test_uses_created_at_when_completed_at_is_null(): void
    {
        Carbon::setTestNow('2026-03-17 10:00:00');
        $this->makeCreditSale([
            'total' => 200,
            'amount_paid' => 0,
            'amount_pending' => 200,
            'customer_id' => $this->customer->id,
            'status' => SaleStatus::Pending->value,
            'completed_at' => null,
        ]);
        Carbon::setTestNow('2026-04-17 10:00:00');

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(200.0, $r['31-60']);
    }
}
