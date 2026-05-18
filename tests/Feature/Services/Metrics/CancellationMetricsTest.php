<?php

namespace Tests\Feature\Services\Metrics;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\Metrics\CancellationMetrics;
use App\Services\Metrics\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CancellationMetricsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private CancellationMetrics $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->svc = app(CancellationMetrics::class);
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Crea una venta cancelada. Por default toma cancelled_by = cajero, total 100.
     * Para "venta cancelada que pasó por solicitud" pasa cancel_requested_at y
     * cancel_requested_by; el tiempo de respuesta sale de la diferencia.
     */
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

    private function range(): DateRange
    {
        // Cubre abril 2026 — donde caen todas las fixtures (setTestNow 2026-04-17).
        return DateRange::custom('2026-04-01', '2026-04-17');
    }

    public function test_summary_counts_cancelled_in_range_and_excludes_outside(): void
    {
        $this->makeCancelledSale(['total' => 150, 'cancelled_at' => '2026-04-10 09:00:00']);
        $this->makeCancelledSale(['total' => 80, 'cancelled_at' => '2026-04-15 09:00:00']);
        // Fuera de rango
        $this->makeCancelledSale(['total' => 999, 'cancelled_at' => '2026-03-01 09:00:00']);

        $current = $this->svc->summary($this->range(), $this->branch->id, $this->tenant->id)['current'];

        $this->assertSame(2, $current['cancelled_count']);
        $this->assertSame(230.0, $current['cancelled_amount']);
    }

    public function test_summary_pct_of_sales_uses_gross_sales_as_denominator(): void
    {
        // Una venta cobrada de 800 y una cancelada de 200 en el mes.
        $this->makeCompletedSale(['total' => 800, 'completed_at' => '2026-04-12 10:00:00']);
        $this->makeCancelledSale(['total' => 200, 'cancelled_at' => '2026-04-15 10:00:00']);

        $current = $this->svc->summary($this->range(), $this->branch->id, $this->tenant->id)['current'];

        $this->assertSame(800.0, $current['gross_sales']);
        $this->assertSame(25.0, $current['pct_of_sales']); // 200 / 800
    }

    public function test_summary_pct_of_sales_is_null_when_no_sales(): void
    {
        $this->makeCancelledSale(['total' => 50]);

        $current = $this->svc->summary($this->range(), $this->branch->id, $this->tenant->id)['current'];

        $this->assertNull($current['pct_of_sales']);
    }

    public function test_summary_response_time_average_only_includes_requests(): void
    {
        // Solicitada 30 min antes
        $this->makeCancelledSale([
            'total' => 100,
            'cancelled_at' => '2026-04-15 10:30:00',
            'cancel_requested_at' => '2026-04-15 10:00:00',
            'cancel_requested_by' => $this->cajero->id,
        ]);
        // Solicitada 90 min antes → promedio 60
        $this->makeCancelledSale([
            'total' => 100,
            'cancelled_at' => '2026-04-15 12:00:00',
            'cancel_requested_at' => '2026-04-15 10:30:00',
            'cancel_requested_by' => $this->cajero->id,
        ]);
        // Cancelación directa: NO entra al promedio.
        $this->makeCancelledSale(['total' => 100, 'cancelled_at' => '2026-04-15 13:00:00']);

        $current = $this->svc->summary($this->range(), $this->branch->id, $this->tenant->id)['current'];

        $this->assertSame(60.0, $current['avg_response_minutes']);
        $this->assertSame(2, $current['from_request_count']);
        $this->assertSame(1, $current['direct_count']);
    }

    public function test_summary_response_time_is_null_when_no_requests(): void
    {
        $this->makeCancelledSale(['total' => 100, 'cancelled_at' => '2026-04-15 10:00:00']);

        $current = $this->svc->summary($this->range(), $this->branch->id, $this->tenant->id)['current'];

        $this->assertNull($current['avg_response_minutes']);
    }

    public function test_daily_zero_fills_empty_days(): void
    {
        $this->makeCancelledSale(['total' => 100, 'cancelled_at' => '2026-04-10 10:00:00']);
        $this->makeCancelledSale(['total' => 200, 'cancelled_at' => '2026-04-10 14:00:00']);
        $this->makeCancelledSale(['total' => 50, 'cancelled_at' => '2026-04-12 10:00:00']);

        $daily = $this->svc->daily($this->range(), $this->branch->id, $this->tenant->id);

        // 17 días (abril 1-17 con setTestNow al 17).
        $this->assertCount(17, $daily);

        $byDay = collect($daily)->keyBy('day');
        $this->assertSame(2, $byDay['2026-04-10']['count']);
        $this->assertSame(300.0, $byDay['2026-04-10']['amount']);
        $this->assertSame(1, $byDay['2026-04-12']['count']);
        $this->assertSame(50.0, $byDay['2026-04-12']['amount']);
        // Día sin cancelaciones → 0.
        $this->assertSame(0, $byDay['2026-04-11']['count']);
        $this->assertSame(0.0, $byDay['2026-04-11']['amount']);
    }

    public function test_by_reason_groups_and_null_becomes_sin_motivo(): void
    {
        $this->makeCancelledSale(['total' => 100, 'cancel_reason' => 'Producto equivocado']);
        $this->makeCancelledSale(['total' => 200, 'cancel_reason' => 'Producto equivocado']);
        $this->makeCancelledSale(['total' => 50, 'cancel_reason' => 'Cliente ya no quiso']);
        $this->makeCancelledSale(['total' => 30, 'cancel_reason' => null]);

        $rows = $this->svc->byReason($this->range(), $this->branch->id, $this->tenant->id);

        $byReason = collect($rows)->keyBy('reason');
        $this->assertSame(2, $byReason['Producto equivocado']['count']);
        $this->assertSame(300.0, $byReason['Producto equivocado']['amount']);
        $this->assertSame(50.0, $byReason['Cliente ya no quiso']['amount']);
        $this->assertSame(30.0, $byReason['Sin motivo']['amount']);
        // % del conteo total: 2/4 = 50%
        $this->assertSame(50.0, $byReason['Producto equivocado']['pct_of_count']);
        // Orden: motivo con más conteo primero.
        $this->assertSame('Producto equivocado', $rows[0]['reason']);
    }

    public function test_by_cashier_separates_cancelled_by_and_requested_by(): void
    {
        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);

        // Cajero1 SOLICITA, admin-sucursal CANCELA → 1 directa para admin + 1 solicitada para cajero
        $this->makeCancelledSale([
            'total' => 100,
            'cancelled_at' => '2026-04-15 10:30:00',
            'cancelled_by' => $this->adminSucursal->id,
            'cancel_requested_by' => $this->cajero->id,
            'cancel_requested_at' => '2026-04-15 10:00:00',
        ]);
        // Cancelación directa por admin-sucursal (sin solicitud previa)
        $this->makeCancelledSale([
            'total' => 200,
            'cancelled_at' => '2026-04-15 11:00:00',
            'cancelled_by' => $this->adminSucursal->id,
        ]);
        // Cajero2 solicita, admin cancela
        $this->makeCancelledSale([
            'total' => 50,
            'cancelled_at' => '2026-04-15 12:00:00',
            'cancelled_by' => $this->adminSucursal->id,
            'cancel_requested_by' => $otherCajero->id,
            'cancel_requested_at' => '2026-04-15 11:30:00',
        ]);

        $rows = $this->svc->byCashier($this->range(), $this->branch->id, $this->tenant->id);
        $byId = collect($rows)->keyBy('id');

        // Admin canceló 3
        $this->assertSame(3, $byId[$this->adminSucursal->id]['cancelled_count']);
        $this->assertSame(350.0, $byId[$this->adminSucursal->id]['cancelled_amount']);
        $this->assertSame(0, $byId[$this->adminSucursal->id]['requested_count']);

        // Cajero1 no canceló nada, solicitó 1
        $this->assertSame(0, $byId[$this->cajero->id]['cancelled_count']);
        $this->assertSame(1, $byId[$this->cajero->id]['requested_count']);

        // Cajero2 solicitó 1
        $this->assertSame(1, $byId[$otherCajero->id]['requested_count']);

        // Orden: el que canceló más, primero
        $this->assertSame($this->adminSucursal->id, $rows[0]['id']);
    }

    public function test_by_branch_returns_one_row_per_branch_with_pct_of_sales(): void
    {
        // Sucursal 1: 600 cobrado, 200 cancelado → 33.33%
        $this->makeCompletedSale(['total' => 600, 'branch_id' => $this->branch->id, 'completed_at' => '2026-04-12 10:00:00']);
        $this->makeCancelledSale(['total' => 200, 'branch_id' => $this->branch->id, 'cancelled_at' => '2026-04-15 10:00:00']);

        // Sucursal 2: 1000 cobrado, 100 cancelado → 10%
        $this->makeCompletedSale(['total' => 1000, 'branch_id' => $this->secondBranch->id, 'completed_at' => '2026-04-12 10:00:00']);
        $this->makeCancelledSale(['total' => 100, 'branch_id' => $this->secondBranch->id, 'cancelled_at' => '2026-04-15 10:00:00']);

        $rows = $this->svc->byBranch($this->range(), $this->tenant->id);
        $byId = collect($rows)->keyBy('branch_id');

        $this->assertSame(200.0, $byId[$this->branch->id]['cancelled_amount']);
        $this->assertSame(33.33, $byId[$this->branch->id]['pct_of_sales']);
        $this->assertSame(100.0, $byId[$this->secondBranch->id]['cancelled_amount']);
        $this->assertSame(10.0, $byId[$this->secondBranch->id]['pct_of_sales']);

        // Orden: monto desc
        $this->assertSame($this->branch->id, $rows[0]['branch_id']);
    }

    public function test_branch_scope_isolates_sucursales(): void
    {
        $this->makeCancelledSale(['total' => 500, 'branch_id' => $this->branch->id]);
        $this->makeCancelledSale(['total' => 999, 'branch_id' => $this->secondBranch->id]);

        $r1 = $this->svc->summary($this->range(), $this->branch->id, $this->tenant->id)['current'];
        $r2 = $this->svc->summary($this->range(), $this->secondBranch->id, $this->tenant->id)['current'];

        $this->assertSame(500.0, $r1['cancelled_amount']);
        $this->assertSame(999.0, $r2['cancelled_amount']);

        // Sin filtro de sucursal: la suma de las dos.
        $rAll = $this->svc->summary($this->range(), null, $this->tenant->id)['current'];
        $this->assertSame(1499.0, $rAll['cancelled_amount']);
    }
}
