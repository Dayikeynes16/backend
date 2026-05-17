<?php

namespace Tests\Feature\Services;

use App\Enums\SaleStatus;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\DailySummaryService;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\SalesMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Garantiza que DailySummaryService produzca exactamente los mismos números
 * de venta que SalesMetrics para un día — es la fuente única de verdad
 * compartida entre Dashboard, Historial, Pagos y el módulo de Métricas.
 */
class DailySummaryServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private const DATE = '2026-04-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeSale(array $attrs = []): Sale
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 100,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed,
            'completed_at' => null,
        ], $attrs));

        if ($createdAt) {
            $sale->forceFill(['created_at' => $createdAt])->save();
        }

        return $sale;
    }

    private function pay(Sale $sale, string $method, float $amount, string $at): Payment
    {
        // created_at no es fillable en Payment → se fuerza con forceFill.
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => $method,
            'amount' => $amount,
        ]);
        $payment->forceFill(['created_at' => Carbon::parse($at)])->save();

        return $payment;
    }

    private function seedScenario(): void
    {
        $d = self::DATE;

        // A: cobrada hoy, $1000, pago efectivo hoy.
        $a = $this->makeSale(['total' => 1000, 'amount_paid' => 1000, 'completed_at' => Carbon::parse("$d 10:00")]);
        $this->pay($a, 'cash', 1000, "$d 10:05");
        // Pago soft-deleted: NO debe contar en la cobranza.
        $this->pay($a, 'cash', 100, "$d 10:30")->delete();

        // B: completed a crédito (queda saldo pendiente), $500, abono tarjeta hoy.
        $b = $this->makeSale(['total' => 500, 'amount_paid' => 200, 'amount_pending' => 300, 'completed_at' => Carbon::parse("$d 11:00")]);
        $this->pay($b, 'card', 200, "$d 11:00");

        // C: pendiente, $400, creada hoy, sin completar.
        $this->makeSale(['total' => 400, 'amount_paid' => 0, 'amount_pending' => 400, 'status' => SaleStatus::Pending, 'completed_at' => null, 'created_at' => Carbon::parse("$d 12:00")]);

        // D: cancelada hoy, $999 (no cuenta en netas; sí en cancelaciones).
        $this->makeSale(['total' => 999, 'status' => SaleStatus::Cancelled, 'completed_at' => Carbon::parse("$d 09:30"), 'cancelled_at' => Carbon::parse("$d 13:00"), 'created_at' => Carbon::parse("$d 09:00")]);

        // E: venta de AYER, abonada HOY ($800 transferencia) → abono "de cuentas anteriores".
        $e = $this->makeSale(['total' => 800, 'amount_paid' => 800, 'completed_at' => Carbon::parse('2026-04-14 16:00'), 'created_at' => Carbon::parse('2026-04-14 09:00')]);
        $this->pay($e, 'transfer', 800, "$d 15:00");

        // F: venta de MAÑANA → fuera del día.
        $this->makeSale(['total' => 7777, 'amount_paid' => 7777, 'completed_at' => Carbon::parse('2026-04-16 10:00')]);
    }

    public function test_sales_block_matches_sales_metrics_for_the_day(): void
    {
        $this->seedScenario();

        $day = app(DailySummaryService::class)->forDate($this->branch->id, $this->tenant->id, self::DATE);
        $metrics = app(SalesMetrics::class)->summary(DateRange::custom(self::DATE, self::DATE), $this->branch->id, $this->tenant->id);
        $m = $metrics['current'];

        $this->assertEqualsWithDelta($m['gross_sales'], $day['sales']['gross_sales'], 0.01);
        $this->assertEqualsWithDelta($m['net_sales'], $day['sales']['net_sales'], 0.01);
        $this->assertSame($m['ticket_count'], $day['sales']['ticket_count']);
        $this->assertEqualsWithDelta($m['cancelled_amount'], $day['sales']['cancelled_amount'], 0.01);
        $this->assertSame($m['cancelled_count'], $day['sales']['cancelled_count']);
        $this->assertEqualsWithDelta(
            round($m['net_sales'] / max($m['ticket_count'], 1), 2),
            $day['sales']['avg_ticket'],
            0.01
        );

        // Valores concretos del escenario: solo completadas (A 1000 + B 500).
        // C es pending → no cuenta. D es cancelada → no cuenta, se reporta aparte.
        $this->assertEqualsWithDelta(1500.0, $day['sales']['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(1500.0, $day['sales']['net_sales'], 0.01);   // net == gross (no se restan cancelaciones)
        $this->assertSame(2, $day['sales']['ticket_count']);
        $this->assertEqualsWithDelta(999.0, $day['sales']['cancelled_amount'], 0.01);
        $this->assertSame(1, $day['sales']['cancelled_count']);
    }

    public function test_yesterday_block_and_delta_are_correct(): void
    {
        $this->seedScenario();

        $day = app(DailySummaryService::class)->forDate($this->branch->id, $this->tenant->id, self::DATE);

        // Ayer (2026-04-14): solo la venta E, $800 cobrada.
        $this->assertEqualsWithDelta(800.0, $day['sales_yesterday']['net_sales'], 0.01);
        $this->assertSame(1, $day['sales_yesterday']['ticket_count']);

        // delta = (1500 - 800) / 800 * 100 = 87.5
        $this->assertSame(87.5, $day['delta_pct']);
    }

    public function test_collections_block_totals_and_origin_split(): void
    {
        $this->seedScenario();

        $day = app(DailySummaryService::class)->forDate($this->branch->id, $this->tenant->id, self::DATE);
        $c = $day['collections'];

        // Total cobrado hoy: A 1000 (efectivo) + B 200 (tarjeta) + E 800 (transferencia) = 2000.
        // El pago soft-deleted de $100 NO cuenta.
        $this->assertEqualsWithDelta(2000.0, $c['total'], 0.01);
        $this->assertSame(3, $c['payment_count']);

        // Coincide con el "collected" que reporta SalesMetrics para el mismo día.
        $m = app(SalesMetrics::class)->summary(DateRange::custom(self::DATE, self::DATE), $this->branch->id, $this->tenant->id)['current'];
        $this->assertEqualsWithDelta($m['collected'], $c['total'], 0.01);

        // Split por antigüedad: A y B son ventas de hoy; E es de ayer.
        $this->assertEqualsWithDelta(1200.0, $c['from_today'], 0.01);
        $this->assertEqualsWithDelta(800.0, $c['from_previous'], 0.01);
        $this->assertEqualsWithDelta($c['total'], $c['from_today'] + $c['from_previous'], 0.01);

        $byMethod = collect($c['by_method'])->keyBy('method');
        $this->assertEqualsWithDelta(1000.0, $byMethod['cash']['total'], 0.01);
        $this->assertEqualsWithDelta(200.0, $byMethod['card']['total'], 0.01);
        $this->assertEqualsWithDelta(800.0, $byMethod['transfer']['total'], 0.01);
        // El abono a la venta vieja se marca como "from_previous".
        $this->assertEqualsWithDelta(800.0, $byMethod['transfer']['from_previous'], 0.01);
        $this->assertEqualsWithDelta(0.0, $byMethod['transfer']['from_today'], 0.01);
    }

    public function test_collections_filters_by_user_id_when_provided(): void
    {
        $d = self::DATE;

        // Sale con 2 pagos: $400 efectivo del cajero, $200 tarjeta del adminSucursal.
        $sale = $this->makeSale(['total' => 600, 'amount_paid' => 600, 'completed_at' => Carbon::parse("$d 10:00")]);

        // Pago del cajero
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 400])
            ->forceFill(['created_at' => Carbon::parse("$d 10:05")])->save();
        // Pago del adminSucursal
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->adminSucursal->id, 'method' => 'card', 'amount' => 200])
            ->forceFill(['created_at' => Carbon::parse("$d 11:00")])->save();

        $svc = app(DailySummaryService::class);

        // Sin filtro: total = 600, 2 pagos.
        $all = $svc->forDate($this->branch->id, $this->tenant->id, $d);
        $this->assertEqualsWithDelta(600.0, $all['collections']['total'], 0.01);
        $this->assertSame(2, $all['collections']['payment_count']);

        // Filtrado por cajero: solo el pago de $400 efectivo.
        $filtered = $svc->forDate($this->branch->id, $this->tenant->id, $d, ['cash', 'card', 'transfer'], $this->cajero->id);
        $this->assertEqualsWithDelta(400.0, $filtered['collections']['total'], 0.01);
        $this->assertSame(1, $filtered['collections']['payment_count']);
        $byMethod = collect($filtered['collections']['by_method'])->keyBy('method');
        $this->assertEqualsWithDelta(400.0, $byMethod['cash']['total'], 0.01);
        $this->assertEqualsWithDelta(0.0, $byMethod['card']['total'], 0.01);

        // Filtrado por adminSucursal: solo $200 tarjeta.
        $filtered2 = $svc->forDate($this->branch->id, $this->tenant->id, $d, ['cash', 'card', 'transfer'], $this->adminSucursal->id);
        $this->assertEqualsWithDelta(200.0, $filtered2['collections']['total'], 0.01);
        $this->assertSame(1, $filtered2['collections']['payment_count']);
    }

    public function test_hourly_series_uses_canonical_date_and_excludes_cancelled(): void
    {
        $this->seedScenario();

        $hourly = app(DailySummaryService::class)->hourlySeries($this->branch->id, $this->tenant->id, self::DATE);

        // Solo completadas: A 10:00 → $1000, B 11:00 → $500.
        $this->assertEqualsWithDelta(1000.0, $hourly[10]['total'], 0.01);
        $this->assertSame(1, $hourly[10]['trx']);
        $this->assertEqualsWithDelta(500.0, $hourly[11]['total'], 0.01);
        // C (pending, 12:00), D (cancelada, 09:30) y E (venta de ayer) NO aparecen.
        $this->assertArrayNotHasKey(12, $hourly);
        $this->assertArrayNotHasKey(9, $hourly);
        $this->assertArrayNotHasKey(16, $hourly);
    }

    public function test_branch_null_aggregates_whole_tenant(): void
    {
        // Venta en la segunda sucursal el mismo día.
        Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F-B2', 'payment_method' => 'cash',
            'total' => 250, 'amount_paid' => 250, 'amount_pending' => 0,
            'origin' => 'admin', 'status' => SaleStatus::Completed,
            'completed_at' => Carbon::parse(self::DATE.' 10:00'),
        ]);
        // Venta en la primera sucursal.
        $this->makeSale(['total' => 100, 'amount_paid' => 100, 'completed_at' => Carbon::parse(self::DATE.' 11:00')]);

        $svc = app(DailySummaryService::class);

        $branch1 = $svc->forDate($this->branch->id, $this->tenant->id, self::DATE);
        $allBranches = $svc->forDate(null, $this->tenant->id, self::DATE);

        $this->assertEqualsWithDelta(100.0, $branch1['sales']['net_sales'], 0.01);
        $this->assertEqualsWithDelta(350.0, $allBranches['sales']['net_sales'], 0.01); // 100 + 250
        $this->assertSame(2, $allBranches['sales']['ticket_count']);
    }
}
