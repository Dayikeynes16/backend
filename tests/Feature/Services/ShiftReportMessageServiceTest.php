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
        $this->svc = new ShiftReportMessageService();
        Carbon::setTestNow('2026-04-24 20:45:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
            'declared_amount' => 7680,
            'declared_card' => 3200,
            'declared_transfer' => 1140,
            'expected_amount' => 7700,
            'difference' => -20,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ], $attrs));
    }

    public function test_basic_message_contains_key_sections_and_totals(): void
    {
        $shift = $this->makeClosedShift();

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('*CORTE DE CAJA*', $text);
        $this->assertStringContainsString('Test — Sucursal 1', $text);
        $this->assertStringContainsString('Cajero: cajero', $text);
        $this->assertStringContainsString('24/04/2026 20:45', $text);
        $this->assertStringContainsString('Total vendido: $12,540.00', $text);
        $this->assertStringContainsString('N.° de ventas: 48', $text);
        $this->assertStringContainsString('Efectivo: $8,200.00', $text);
        $this->assertStringContainsString('Tarjeta: $3,200.00', $text);
        $this->assertStringContainsString('Transferencia: $1,140.00', $text);
        $this->assertStringContainsString('Fondo inicial: $500.00', $text);
        $this->assertStringContainsString('Esperado: $7,700.00', $text);
        $this->assertStringContainsString('Declarado: $7,680.00', $text);
        $this->assertStringContainsString('Diferencia: -$20.00', $text);
    }

    public function test_omits_cancelled_line_when_there_are_none(): void
    {
        $shift = $this->makeClosedShift();

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringNotContainsString('Canceladas:', $text);
    }

    public function test_includes_cancelled_count_and_amount_when_present(): void
    {
        $shift = $this->makeClosedShift();

        $this->makeCancelledSaleAt('C1', 200, '2026-04-24 14:00:00');
        $this->makeCancelledSaleAt('C2', 140, '2026-04-24 15:00:00');
        // Cancelled outside window — should NOT count
        $this->makeCancelledSaleAt('C3', 999, '2026-04-23 09:00:00');

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
        // created_at is a guarded timestamp; force it via raw update so it
        // falls inside or outside the shift window deterministically.
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAt]);
    }

    public function test_includes_withdrawals_when_present(): void
    {
        $shift = $this->makeClosedShift();
        CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $this->cajero->id,
            'amount' => 600,
            'reason' => 'Compra insumos',
            'created_at' => Carbon::parse('2026-04-24 18:00:00'),
        ]);
        CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $this->cajero->id,
            'amount' => 400,
            'reason' => 'Cambio',
            'created_at' => Carbon::parse('2026-04-24 19:00:00'),
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Retiros: $1,000.00', $text);
    }

    public function test_omits_withdrawals_line_when_none(): void
    {
        $shift = $this->makeClosedShift();

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringNotContainsString('Retiros:', $text);
    }

    public function test_shows_sin_diferencias_when_all_zero(): void
    {
        $shift = $this->makeClosedShift([
            'declared_amount' => 7700,
            'difference' => 0,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Sin diferencias', $text);
        $this->assertStringNotContainsString('• Total: ', $text);
    }

    public function test_shows_per_method_breakdown_when_any_diff_non_zero(): void
    {
        $shift = $this->makeClosedShift([
            'difference' => -20,
            'difference_card' => 10,
            'difference_transfer' => 0,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Efectivo: -$20.00', $text);
        $this->assertStringContainsString('Tarjeta: +$10.00', $text);
        $this->assertStringContainsString('Transferencia: $0.00', $text);
        $this->assertStringContainsString('Total: -$10.00', $text);
    }

    public function test_shows_no_aplica_when_method_was_not_declared(): void
    {
        $shift = $this->makeClosedShift([
            'declared_card' => null,
            'difference_card' => 0,
            'difference' => -5,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Tarjeta: no aplica', $text);
    }

    public function test_includes_notes_when_present(): void
    {
        $shift = $this->makeClosedShift(['notes' => 'Faltante menor, sin incidentes']);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Notas: Faltante menor, sin incidentes', $text);
    }

    public function test_omits_notes_section_when_null(): void
    {
        $shift = $this->makeClosedShift(['notes' => null]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringNotContainsString('Notas:', $text);
    }

    public function test_text_stays_under_max_bytes(): void
    {
        $shift = $this->makeClosedShift(['notes' => str_repeat('X', 10000)]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertLessThanOrEqual(3500, strlen($text));
    }
}
