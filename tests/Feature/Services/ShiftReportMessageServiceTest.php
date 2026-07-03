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
        $this->svc = app(ShiftReportMessageService::class);
        Carbon::setTestNow('2026-04-24 20:45:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Fixture base: expected efectivo 8700, declarado 8680 → faltante neto $20
     * (tarjeta y transferencia cuadran).
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
            'total_cash_expenses' => 0,
            'total_cash_provider_payments' => 0,
            'total_sales' => 12540,
            'sale_count' => 48,
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
        // Veredicto NETO arriba del todo (faltante neto de $20).
        $this->assertStringContainsString('Faltante total de $20.00', $text);
        // Resumen del turno.
        $this->assertStringContainsString('RESUMEN DEL TURNO', $text);
        $this->assertStringContainsString('Vendido: 48 ventas', $text);
        $this->assertStringContainsString('*$12,540.00*', $text);
        $this->assertStringContainsString('Cobrado en el turno: $12,540.00', $text);
        $this->assertStringContainsString('Esperado total', $text);
        // Desglose por método.
        $this->assertStringContainsString('DESGLOSE POR MÉTODO', $text);
        // Arqueo de efectivo con la cuenta explícita.
        $this->assertStringContainsString('ARQUEO DE EFECTIVO', $text);
        $this->assertStringContainsString('Fondo inicial: $500.00', $text);
        $this->assertStringContainsString('+ Efectivo cobrado: $8,200.00', $text);
        $this->assertStringContainsString('Esperado en cajón: *$8,700.00*', $text);
        $this->assertStringContainsString('Contado por el cajero: $8,680.00', $text);
    }

    public function test_separates_today_sales_from_old_debt_collections(): void
    {
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
            'declared_amount' => 35500,
            'expected_amount' => 35500,
            'difference' => 0,
            'declared_card' => null,
            'declared_transfer' => null,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Vendido: 4 ventas', $text);
        $this->assertStringContainsString('*$5,000.00*', $text);
        $this->assertStringContainsString('Cobrado en el turno: $35,000.00', $text);
        $this->assertStringContainsString('De ventas del turno: $5,000.00', $text);
        $this->assertStringContainsString('Abonos a fiados anteriores: $30,000.00', $text);
    }

    public function test_omits_split_lines_when_no_old_debt_collections(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringNotContainsString('De ventas del turno:', $text);
        $this->assertStringNotContainsString('Abonos a fiados anteriores:', $text);
    }

    public function test_includes_cancelled_count_and_amount_when_present(): void
    {
        $shift = $this->makeClosedShift();

        $this->makeCancelledSaleAt('C1', 200, '2026-04-24 14:00:00');
        $this->makeCancelledSaleAt('C2', 140, '2026-04-24 15:00:00');
        $this->makeCancelledSaleAt('C3', 999, '2026-04-23 09:00:00'); // fuera de la ventana

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Canceladas: 2 ($340.00)', $text);
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
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAt]);
    }

    public function test_verdict_says_caja_cuadrada_when_all_zero(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8700, 'difference' => 0,
        ]));

        $this->assertStringContainsString('Caja cuadrada', $text);
    }

    public function test_verdict_full_compensation_does_not_say_faltante_total(): void
    {
        // Falta $50 efectivo, sobra $50 tarjeta → neto cuadra.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8650, 'difference' => -50,
            'declared_card' => 3250, 'difference_card' => 50,
        ]));

        $this->assertStringContainsString('cuadra en total', $text);
        $this->assertStringContainsString('faltan $50.00 en efectivo', $text);
        $this->assertStringContainsString('sobran $50.00 en tarjeta', $text);
        $this->assertStringNotContainsString('Faltante total', $text);
    }

    public function test_verdict_partial_compensation_reports_net(): void
    {
        // Falta $80 efectivo, sobra $30 tarjeta → faltante real $50.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8620, 'difference' => -80,
            'declared_card' => 3230, 'difference_card' => 30,
        ]));

        $this->assertStringContainsString('Faltante total de $50.00', $text);
        $this->assertStringContainsString('faltan $80.00 en efectivo', $text);
        $this->assertStringContainsString('sobran $30.00 en tarjeta', $text);
    }

    public function test_arqueo_includes_cash_expenses_and_purchases(): void
    {
        // Bug corregido: el arqueo ahora resta gastos y compras en efectivo.
        // 500 + 8200 − 1000 (retiros) − 300 (gastos) − 200 (compras) = 7200.
        $shift = $this->makeClosedShift([
            'total_cash_expenses' => 300,
            'total_cash_provider_payments' => 200,
            'expected_amount' => 7200,
            'declared_amount' => 7200,
            'difference' => 0,
        ]);
        CashWithdrawal::create([
            'shift_id' => $shift->id, 'user_id' => $this->cajero->id,
            'amount' => 1000, 'reason' => 'Retiro', 'created_at' => Carbon::parse('2026-04-24 18:00:00'),
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('− Retiros: $1,000.00', $text);
        $this->assertStringContainsString('− Gastos en efectivo: $300.00', $text);
        $this->assertStringContainsString('− Compras en efectivo: $200.00', $text);
        $this->assertStringContainsString('Esperado en cajón: *$7,200.00*', $text);
    }

    public function test_verdict_when_nothing_declared(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => null, 'declared_card' => null, 'declared_transfer' => null,
            'difference' => 0, 'difference_card' => 0, 'difference_transfer' => 0,
        ]));

        $this->assertStringContainsString('sin conteo declarado', $text);
        $this->assertStringNotContainsString('Caja cuadrada', $text);
    }

    public function test_includes_notes_when_present(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift(['notes' => 'Sin incidentes']));

        $this->assertStringContainsString('Notas del cajero: Sin incidentes', $text);
    }

    public function test_text_stays_under_max_bytes(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift(['notes' => str_repeat('X', 10000)]));

        $this->assertLessThanOrEqual(3500, strlen($text));
    }

    public function test_verdict_sobrante_total_when_positive_net(): void
    {
        // Sobra $50 en efectivo, el resto cuadra → sobrante neto de $50.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8750, 'difference' => 50,
        ]));

        $this->assertStringContainsString('Sobrante total de $50.00', $text);
        $this->assertStringContainsString('*Diferencia total: +$50.00* ⚠️', $text);
    }

    public function test_desglose_shows_per_method_lines(): void
    {
        // Fixture base: efectivo −$20 (faltante), tarjeta y transferencia cuadran.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringContainsString('Efectivo: $8,700.00 → $8,680.00 (-$20.00 faltante)', $text);
        $this->assertStringContainsString('Tarjeta: $3,200.00 → $3,200.00 ✅', $text);
    }

    public function test_desglose_marks_method_as_no_declarado(): void
    {
        // Tarjeta con movimiento pero sin declarar → se marca "(no declarado)".
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_card' => null, 'difference_card' => 0,
        ]));

        $this->assertStringContainsString('Tarjeta: $3,200.00 _(no declarado)_', $text);
    }

    public function test_arqueo_shows_cash_difference_line(): void
    {
        // Fixture base: efectivo declarado 8680 vs esperado 8700 → −$20 en el arqueo.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringContainsString('Contado por el cajero: $8,680.00', $text);
        $this->assertStringContainsString('Diferencia: -$20.00', $text);
    }

    public function test_cross_balanced_total_line_uses_scale_not_check(): void
    {
        // Neto en cero pero con descuadres cruzados: la "Diferencia total" no debe
        // marcarse con ✅ (contradiría el encabezado ⚖️); se usa ⚖️.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8650, 'difference' => -50,
            'declared_card' => 3250, 'difference_card' => 50,
        ]));

        $this->assertStringContainsString('*Diferencia total: $0.00* ⚖️', $text);
        $this->assertStringNotContainsString('$0.00* ✅', $text);
    }
}
