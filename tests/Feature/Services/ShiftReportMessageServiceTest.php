<?php

namespace Tests\Feature\Services;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use App\Models\Sale;
use App\Services\ShiftReportMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftReportMessageServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ShiftReportMessageService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->svc = new ShiftReportMessageService;
        Carbon::setTestNow('2026-04-24 20:45:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Fixture coherente: expected = opening (500) + total_cash (8200) − retiros (0) = 8700.
     * declared 8680 → diferencia −20 (un pequeño faltante de efectivo).
     */
    private function makeClosedShift(array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => Carbon::parse('2026-04-24 12:30:00'),
            'closed_at' => Carbon::parse('2026-04-24 20:45:00'),
            'opening_amount' => 500,
            'total_cash' => 8200,
            'total_card' => 3200,
            'total_transfer' => 1140,
            'total_sales' => 12540,
            'sale_count' => 48,
            // Fixture por default: todo cobrado fueron ventas del propio turno
            // (sin abonos retroactivos). Las pruebas que cubren ese caso
            // sobreescriben estos valores.
            'sales_generated_amount' => 12540,
            'sales_generated_count' => 48,
            'collections_from_today_amount' => 12540,
            'collections_from_previous_amount' => 0,
            'declared_amount' => 8680,
            'declared_card' => 3200,
            'declared_transfer' => 1140,
            'expected_amount' => 8700,
            'difference' => -20,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ], $attrs));
    }

    public function test_basic_message_contains_key_sections_and_totals(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringContainsString('*CORTE DE CAJA*', $text);
        $this->assertStringContainsString('Test — Sucursal 1', $text);
        $this->assertStringContainsString('Cierre: 24/04/2026 20:45', $text);
        $this->assertStringContainsString('Cajero: cajero', $text);
        // Veredicto del arqueo arriba del todo.
        $this->assertStringContainsString('Faltante de $20.00 en efectivo', $text);
        // Ventas del turno (lo que se vendió) vs dinero cobrado.
        $this->assertStringContainsString('VENTAS DEL TURNO', $text);
        $this->assertStringContainsString('48 ventas', $text);
        $this->assertStringContainsString('*$12,540.00*', $text);
        $this->assertStringContainsString('DINERO COBRADO EN EL TURNO', $text);
        $this->assertStringContainsString('Efectivo: $8,200.00', $text);
        $this->assertStringContainsString('Tarjeta: $3,200.00', $text);
        $this->assertStringContainsString('Transferencia: $1,140.00', $text);
        $this->assertStringContainsString('Total cobrado: $12,540.00', $text);
        // Arqueo de efectivo con la cuenta explícita.
        $this->assertStringContainsString('ARQUEO DE EFECTIVO', $text);
        $this->assertStringContainsString('Fondo inicial: $500.00', $text);
        $this->assertStringContainsString('+ Efectivo cobrado: $8,200.00', $text);
        $this->assertStringContainsString('Esperado en cajón: *$8,700.00*', $text);
        $this->assertStringContainsString('Contado por el cajero: $8,680.00', $text);
        $this->assertStringContainsString('Diferencia: -$20.00', $text);
    }

    public function test_separates_today_sales_from_old_debt_collections(): void
    {
        // Caso del incidente: $30k abonados a deudas viejas + $5k de ventas del turno.
        $shift = $this->makeClosedShift([
            'total_cash' => 35000,
            'total_card' => 0,
            'total_transfer' => 0,
            'total_sales' => 35000,
            'sale_count' => 5,
            'sales_generated_amount' => 5000,
            'sales_generated_count' => 4,
            'collections_from_today_amount' => 5000,
            'collections_from_previous_amount' => 30000,
            'declared_amount' => 35500,   // = opening 500 + cash 35000
            'expected_amount' => 35500,
            'difference' => 0,
            'declared_card' => null,
            'declared_transfer' => null,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        // "Lo vendido" refleja SOLO lo del turno, no los $30k abonados.
        $this->assertStringContainsString('4 ventas', $text);
        $this->assertStringContainsString('*$5,000.00*', $text);
        // El total cobrado sí incluye todo (el cajón cuadra contra esto).
        $this->assertStringContainsString('Total cobrado: $35,000.00', $text);
        // Split explícito de la cobranza.
        $this->assertStringContainsString('De ventas del turno: $5,000.00', $text);
        $this->assertStringContainsString('Abonos a fiados anteriores: $30,000.00', $text);
        // Lo más importante: NO confundir cobranza con vendido.
        $this->assertStringNotContainsString('*$35,000.00*', $text);
    }

    public function test_omits_split_lines_when_no_old_debt_collections(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift()); // from_previous = 0

        $this->assertStringNotContainsString('De ventas del turno:', $text);
        $this->assertStringNotContainsString('Abonos a fiados anteriores:', $text);
    }

    public function test_omits_cancelled_line_when_there_are_none(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringNotContainsString('Canceladas:', $text);
    }

    public function test_includes_cancelled_count_and_amount_when_present(): void
    {
        $shift = $this->makeClosedShift();

        $this->makeCancelledSaleAt('C1', 200, '2026-04-24 14:00:00');
        $this->makeCancelledSaleAt('C2', 140, '2026-04-24 15:00:00');
        // Cancelada fuera de la ventana del turno — NO debe contar.
        $this->makeCancelledSaleAt('C3', 999, '2026-04-23 09:00:00');

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Canceladas: 2 ($340.00)', $text);
        $this->assertStringContainsString('no cuentan en el total', $text);
    }

    private function makeCancelledSaleAt(string $folio, float $total, string $createdAt): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => $folio,
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Cancelled->value,
        ]);
        // created_at es timestamp guarded; lo forzamos por update directo para
        // colocarlo dentro o fuera de la ventana del turno de forma determinista.
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAt]);
    }

    public function test_includes_withdrawals_with_the_cash_math(): void
    {
        // Con retiros, el arqueo debe mostrarlos en la cuenta y el "esperado"
        // ya considerarlos: 500 + 8200 − 1000 = 7700, declarado 7680 → −20.
        $shift = $this->makeClosedShift([
            'expected_amount' => 7700,
            'declared_amount' => 7680,
            'difference' => -20,
        ]);
        CashWithdrawal::create([
            'shift_id' => $shift->id, 'user_id' => $this->cajero->id,
            'amount' => 600, 'reason' => 'Compra insumos',
            'created_at' => Carbon::parse('2026-04-24 18:00:00'),
        ]);
        CashWithdrawal::create([
            'shift_id' => $shift->id, 'user_id' => $this->cajero->id,
            'amount' => 400, 'reason' => 'Cambio',
            'created_at' => Carbon::parse('2026-04-24 19:00:00'),
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Retiros: $1,000.00', $text);
        $this->assertStringContainsString('Esperado en cajón: *$7,700.00*', $text);
    }

    public function test_omits_withdrawals_line_when_none(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringNotContainsString('Retiros:', $text);
    }

    public function test_verdict_says_caja_cuadrada_when_all_zero(): void
    {
        $shift = $this->makeClosedShift([
            'declared_amount' => 8700,   // = expected
            'difference' => 0,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Caja cuadrada', $text);
        $this->assertStringContainsString('Diferencia: ninguna', $text);
        // No debe aparecer ningún descuadre por método.
        $this->assertStringNotContainsString('Descuadre en', $text);
    }

    public function test_verdict_says_sobrante_when_cash_over(): void
    {
        $shift = $this->makeClosedShift([
            'declared_amount' => 8750,   // = expected 8700 + 50
            'difference' => 50,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Sobrante de $50.00 en efectivo', $text);
        $this->assertStringContainsString('Diferencia: +$50.00', $text);
        $this->assertStringContainsString('sobrante', $text);
    }

    public function test_verdict_when_cash_count_not_declared(): void
    {
        $shift = $this->makeClosedShift([
            'declared_amount' => null,
            'difference' => 0,
            'declared_card' => null,
            'declared_transfer' => null,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('sin conteo de efectivo declarado', $text);
        $this->assertStringContainsString('Conteo de efectivo no declarado por el cajero', $text);
        $this->assertStringNotContainsString('Caja cuadrada', $text);
    }

    public function test_reports_card_descuadre_when_declared_and_diff_non_zero(): void
    {
        $shift = $this->makeClosedShift([
            'difference' => -20,         // efectivo: faltante
            'declared_card' => 3210,     // tarjeta: declarado 3210 vs registrado 3200
            'difference_card' => 10,     // → sobrante +10
            'difference_transfer' => 0,  // transferencia cuadra
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Diferencia: -$20.00', $text);            // efectivo, en el arqueo
        $this->assertStringContainsString('Descuadre en tarjeta:', $text);
        $this->assertStringContainsString('declarado $3,210.00 vs registrado $3,200.00 (+$10.00)', $text);
        // Transferencia cuadra → no se menciona descuadre.
        $this->assertStringNotContainsString('Descuadre en transferencia', $text);
    }

    public function test_does_not_report_card_descuadre_when_not_declared(): void
    {
        $shift = $this->makeClosedShift([
            'declared_card' => null,
            'difference_card' => 0,
            'declared_amount' => 8695,   // = expected 8700 − 5
            'difference' => -5,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringNotContainsString('Descuadre en tarjeta', $text);
        $this->assertStringNotContainsString('no aplica', $text);
        $this->assertStringContainsString('Diferencia: -$5.00', $text);
        $this->assertStringContainsString('Faltante de $5.00 en efectivo', $text);
    }

    public function test_includes_notes_when_present(): void
    {
        $shift = $this->makeClosedShift(['notes' => 'Faltante menor, sin incidentes']);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Notas del cajero: Faltante menor, sin incidentes', $text);
    }

    public function test_omits_notes_section_when_null(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift(['notes' => null]));

        $this->assertStringNotContainsString('Notas del cajero:', $text);
    }

    public function test_text_stays_under_max_bytes(): void
    {
        $shift = $this->makeClosedShift(['notes' => str_repeat('X', 10000)]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertLessThanOrEqual(3500, strlen($text));
    }
}
