<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CancelRequestIndexTest extends TestCase
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

    private function makeCancelledSale(string $cancelledAt, array $attrs = []): Sale
    {
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

    public function test_stats_history_and_reasons_respect_the_range(): void
    {
        // Hoy
        $this->makeCancelledSale('2026-04-17 10:00:00', ['total' => 100]);
        // Hace 10 días — fuera del preset "today"
        $this->makeCancelledSale('2026-04-07 10:00:00', ['total' => 250]);

        // preset=today → solo la de hoy
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.cancelaciones.index', $this->tenant->slug).'?preset=today')
            ->assertInertia(fn ($page) => $page
                ->where('stats.cancelled_count', 1)
                ->where('stats.cancelled_total', 100)
                ->has('history', 1)
                ->has('topReasons', 1)
            );

        // preset=this_month → ambas
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.cancelaciones.index', $this->tenant->slug).'?from=2026-04-01&to=2026-04-30')
            ->assertInertia(fn ($page) => $page
                ->where('stats.cancelled_count', 2)
                ->where('stats.cancelled_total', 350)
                ->has('history', 2)
            );
    }
}
