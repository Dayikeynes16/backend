<?php

namespace Tests\Feature\Http\Sucursal\Metrics;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CancellationMetricsControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCancelledSale(array $attrs = []): Sale
    {
        $cancelledAt = $attrs['cancelled_at'] ?? '2026-04-15 10:00:00';
        unset($attrs['cancelled_at']);

        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Cancelled->value,
            'cancelled_by' => $this->adminSucursal->id,
            'cancel_reason' => 'Producto equivocado',
        ], $attrs));

        $sale->forceFill(['cancelled_at' => $cancelledAt])->save();

        return $sale;
    }

    public function test_endpoint_returns_summary_daily_reasons_cashier_and_history(): void
    {
        $this->makeCancelledSale(['total' => 100, 'cancelled_at' => '2026-04-15 10:00:00']);
        $this->makeCancelledSale(['total' => 200, 'cancelled_at' => '2026-04-15 12:00:00', 'cancel_reason' => 'Cliente ya no quiso']);

        $response = $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.cancelaciones', $this->tenant->slug).'?from=2026-04-01&to=2026-04-30');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Sucursal/Metricas/Cancelaciones')
            ->where('data.summary.current.cancelled_count', 2)
            ->where('data.summary.current.cancelled_amount', 300)
            ->has('data.daily')
            ->has('data.by_reason', 2)
            ->has('data.by_cashier')
            ->has('history.data', 2)
        );
    }

    public function test_respects_date_range_preset(): void
    {
        $this->makeCancelledSale(['total' => 100, 'cancelled_at' => '2026-04-17 10:00:00']);
        $this->makeCancelledSale(['total' => 999, 'cancelled_at' => '2026-03-01 10:00:00']);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.cancelaciones', $this->tenant->slug).'?preset=today')
            ->assertInertia(fn ($page) => $page
                ->where('data.summary.current.cancelled_count', 1)
                ->where('data.summary.current.cancelled_amount', 100)
            );
    }
}
