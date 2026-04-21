<?php

namespace Tests\Feature\Services\Metrics;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Services\Metrics\CollectionMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Aging buckets: requires PostgreSQL (uses CURRENT_DATE - date cast).
 * Run via: sail artisan test --filter=CollectionMetricsAgingTest
 */
class CollectionMetricsAgingTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private CollectionMetrics $svc;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->svc = app(CollectionMetrics::class);
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
        // Contrato con frontend: CobranzaContent.vue y ClientesContent.vue
        // leen exactamente estas claves.
        $result = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertArrayHasKey('0-30', $result);
        $this->assertArrayHasKey('31-60', $result);
        $this->assertArrayHasKey('61-plus', $result);
    }

    public function test_buckets_sale_with_30_days_into_0_30(): void
    {
        // Fecha de referencia hace 30 días exactos.
        $this->creditSaleAt('2026-03-18 10:00:00', 100);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(100.0, $r['0-30']);
        $this->assertSame(0.0, $r['31-60']);
        $this->assertSame(0.0, $r['61-plus']);
    }

    public function test_buckets_sale_with_31_days_into_31_60(): void
    {
        $this->creditSaleAt('2026-03-17 10:00:00', 100);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(0.0, $r['0-30']);
        $this->assertSame(100.0, $r['31-60']);
    }

    public function test_buckets_sale_with_60_days_into_31_60(): void
    {
        $this->creditSaleAt('2026-02-16 10:00:00', 100);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(100.0, $r['31-60']);
    }

    public function test_buckets_sale_with_61_days_into_61_plus(): void
    {
        $this->creditSaleAt('2026-02-15 10:00:00', 100);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(0.0, $r['31-60']);
        $this->assertSame(100.0, $r['61-plus']);
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

        // 2026-03-17 → 2026-04-17 = 31 días → 31-60 bucket.
        $this->assertSame(200.0, $r['31-60']);
    }

    public function test_includes_pending_and_active_sales(): void
    {
        $this->makeCreditSale([
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'customer_id' => $this->customer->id,
            'status' => SaleStatus::Pending->value,
        ]);
        $this->makeCreditSale([
            'total' => 50,
            'amount_paid' => 0,
            'amount_pending' => 50,
            'customer_id' => $this->customer->id,
            'status' => SaleStatus::Active->value,
        ]);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(150.0, $r['0-30']);
    }

    public function test_excludes_cancelled_sales(): void
    {
        $this->makeCreditSale([
            'total' => 500,
            'amount_paid' => 0,
            'amount_pending' => 500,
            'customer_id' => $this->customer->id,
            'status' => SaleStatus::Cancelled->value,
            'cancelled_at' => '2026-04-15 10:00:00',
        ]);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(0.0, $r['0-30']);
        $this->assertSame(0.0, $r['31-60']);
        $this->assertSame(0.0, $r['61-plus']);
    }

    public function test_excludes_sales_without_customer(): void
    {
        // customer_id NULL → no es cuenta por cobrar.
        $this->makeCreditSale([
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'customer_id' => null,
        ]);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(0.0, $r['0-30']);
    }

    public function test_excludes_soft_deleted_sales(): void
    {
        $sale = $this->makeCreditSale([
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'customer_id' => $this->customer->id,
        ]);
        $sale->delete();

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(0.0, $r['0-30']);
    }

    public function test_returns_zero_in_all_buckets_when_no_pending(): void
    {
        $this->makeCompletedSale(['total' => 100, 'customer_id' => $this->customer->id]);

        $r = $this->svc->aging($this->branch->id, $this->tenant->id);

        $this->assertSame(0.0, $r['0-30']);
        $this->assertSame(0.0, $r['31-60']);
        $this->assertSame(0.0, $r['61-plus']);
    }
}
