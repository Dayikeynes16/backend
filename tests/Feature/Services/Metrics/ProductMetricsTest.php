<?php

namespace Tests\Feature\Services\Metrics;

use App\Models\Product;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\ProductMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProductMetricsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ProductMetrics $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->svc = app(ProductMetrics::class);
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_excludes_soft_deleted_products_from_without_movement(): void
    {
        // Active product without sales → should appear
        $active = $this->makeProduct(['name' => 'Activo sin venta']);

        // Soft-deleted product without sales → should NOT appear
        $deleted = $this->makeProduct(['name' => 'Eliminado sin venta']);
        $deleted->delete();

        $results = $this->svc
            ->withoutMovement(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id, 30)
            ->get();

        $names = $results->pluck('name')->all();

        $this->assertContains('Activo sin venta', $names);
        $this->assertNotContains('Eliminado sin venta', $names);
    }

    public function test_includes_active_products_without_recent_sales(): void
    {
        $product = $this->makeProduct(['name' => 'Sin ventas recientes']);

        $results = $this->svc
            ->withoutMovement(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id, 30)
            ->get();

        $this->assertContains('Sin ventas recientes', $results->pluck('name')->all());
    }

    public function test_excludes_inactive_products(): void
    {
        $this->makeProduct(['name' => 'Inactivo', 'status' => 'inactive']);

        $results = $this->svc
            ->withoutMovement(DateRange::preset('this_month'), $this->branch->id, $this->tenant->id, 30)
            ->get();

        $this->assertNotContains('Inactivo', $results->pluck('name')->all());
    }
}
