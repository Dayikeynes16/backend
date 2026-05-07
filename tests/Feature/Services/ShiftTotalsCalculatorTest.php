<?php

namespace Tests\Feature\Services;

use App\Enums\SaleStatus;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\ShiftTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Cubre el escenario crítico del incidente: un cliente abona $30k de deudas
 * viejas durante el turno. El cálculo debe separar claramente:
 *  - sales_generated_amount = ventas creadas en el turno
 *  - collections_from_previous_amount = abonos a deudas anteriores
 *  - collections_from_today_amount = pagos a ventas del propio turno
 *
 * Antes del fix, el "total vendido" del cierre sumaba los $30k aunque NO eran
 * ventas nuevas — distorsionando el reporte que ve el dueño.
 */
class ShiftTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ShiftTotalsCalculator $calculator;

    private Carbon $shiftOpenedAt;

    private Carbon $shiftClosedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->calculator = new ShiftTotalsCalculator;
        $this->shiftOpenedAt = Carbon::parse('2026-05-07 09:00:00');
        $this->shiftClosedAt = Carbon::parse('2026-05-07 18:00:00');
    }

    public function test_separates_today_sales_from_retroactive_payments(): void
    {
        // 1. Una venta vieja (hace una semana) con saldo pendiente.
        $oldSale = $this->makeSale('OLD-1', 30000, createdAt: '2026-04-30 10:00:00', status: SaleStatus::Active);

        // 2. Una venta del turno actual, creada y cobrada hoy.
        $todaySale = $this->makeSale('NEW-1', 5000, createdAt: '2026-05-07 14:00:00', status: SaleStatus::Active);

        // Pagos durante el turno:
        $this->makePayment($oldSale, 30000, 'cash', '2026-05-07 12:00:00');
        $this->makePayment($todaySale, 5000, 'cash', '2026-05-07 14:30:00');

        $totals = $this->calculator->compute(
            $this->branch->id,
            $this->cajero->id,
            $this->shiftOpenedAt,
            $this->shiftClosedAt,
        );

        // Dinero que entró: $35,000 (cuadra con el cajón).
        $this->assertSame(35000.0, $totals['total_cash']);
        // Pero solo $5,000 son ventas reales del turno.
        $this->assertSame(5000.0, $totals['sales_generated_amount']);
        $this->assertSame(1, $totals['sales_generated_count']);
        // Y los $30k son abonos a cuentas anteriores.
        $this->assertSame(30000.0, $totals['collections_from_previous_amount']);
        $this->assertSame(5000.0, $totals['collections_from_today_amount']);
    }

    public function test_excludes_payments_made_before_shift_opened(): void
    {
        $oldSale = $this->makeSale('OLD-1', 1000, createdAt: '2026-04-30 10:00:00', status: SaleStatus::Active);
        // Pago hecho ANTES de la apertura del turno → no debe entrar.
        $this->makePayment($oldSale, 1000, 'cash', '2026-05-07 08:00:00');

        $totals = $this->calculator->compute(
            $this->branch->id,
            $this->cajero->id,
            $this->shiftOpenedAt,
            $this->shiftClosedAt,
        );

        $this->assertSame(0.0, $totals['total_cash']);
        $this->assertSame(0.0, $totals['collections_from_previous_amount']);
    }

    public function test_excludes_payments_from_other_cashiers(): void
    {
        $sale = $this->makeSale('S-1', 1000, createdAt: '2026-05-07 14:00:00', status: SaleStatus::Active);
        // Pago hecho por otro cajero (admin) durante el turno del cajero principal.
        $this->makePayment($sale, 1000, 'cash', '2026-05-07 14:30:00', userId: $this->adminSucursal->id);

        $totals = $this->calculator->compute(
            $this->branch->id,
            $this->cajero->id,
            $this->shiftOpenedAt,
            $this->shiftClosedAt,
        );

        $this->assertSame(0.0, $totals['total_cash']);
        $this->assertSame(0, $totals['collections_count']);
    }

    public function test_excludes_cancelled_sales_from_generated_amount(): void
    {
        $this->makeSale('OK-1', 1000, createdAt: '2026-05-07 14:00:00', status: SaleStatus::Active);
        $this->makeSale('CANCEL-1', 999, createdAt: '2026-05-07 15:00:00', status: SaleStatus::Cancelled);

        $totals = $this->calculator->compute(
            $this->branch->id,
            $this->cajero->id,
            $this->shiftOpenedAt,
            $this->shiftClosedAt,
        );

        $this->assertSame(1000.0, $totals['sales_generated_amount']);
        $this->assertSame(1, $totals['sales_generated_count']);
    }

    public function test_breakdown_by_method_is_separate_from_age_breakdown(): void
    {
        $oldSale = $this->makeSale('OLD-1', 5000, createdAt: '2026-04-30 10:00:00', status: SaleStatus::Active);
        $todaySale = $this->makeSale('NEW-1', 3000, createdAt: '2026-05-07 14:00:00', status: SaleStatus::Active);

        // Cobranza mixta por método y por antigüedad.
        $this->makePayment($oldSale, 5000, 'card', '2026-05-07 12:00:00');
        $this->makePayment($todaySale, 1000, 'cash', '2026-05-07 14:30:00');
        $this->makePayment($todaySale, 2000, 'transfer', '2026-05-07 15:00:00');

        $totals = $this->calculator->compute(
            $this->branch->id,
            $this->cajero->id,
            $this->shiftOpenedAt,
            $this->shiftClosedAt,
        );

        // Por método: independiente de antigüedad.
        $this->assertSame(1000.0, $totals['total_cash']);
        $this->assertSame(5000.0, $totals['total_card']);
        $this->assertSame(2000.0, $totals['total_transfer']);
        // Por antigüedad: independiente de método.
        $this->assertSame(3000.0, $totals['collections_from_today_amount']);
        $this->assertSame(5000.0, $totals['collections_from_previous_amount']);
    }

    private function makeSale(string $folio, float $total, string $createdAt, SaleStatus $status): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => $folio,
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'admin',
            'status' => $status->value,
        ]);
        // created_at es guarded; lo forzamos con raw para colocarlo dentro o
        // fuera de la ventana del turno deterministicamente.
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAt]);

        return $sale->refresh();
    }

    private function makePayment(Sale $sale, float $amount, string $method, string $createdAt, ?int $userId = null): void
    {
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $userId ?? $this->cajero->id,
            'method' => $method,
            'amount' => $amount,
        ]);
        DB::table('payments')->where('id', $payment->id)->update(['created_at' => $createdAt]);
    }
}
