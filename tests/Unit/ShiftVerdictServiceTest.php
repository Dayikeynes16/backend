<?php

namespace Tests\Unit;

use App\Models\CashRegisterShift;
use App\Services\ShiftVerdictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftVerdictServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ShiftVerdictService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->svc = new ShiftVerdictService;
    }

    /**
     * Fixture base: efectivo esperado 8700 / declarado 8700 (cuadra),
     * tarjeta 3200 y transferencia 1140, ambos cuadrados.
     */
    private function shift(array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::make(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opening_amount' => 500,
            'total_cash' => 8200,
            'total_card' => 3200,
            'total_transfer' => 1140,
            'expected_amount' => 8700,
            'declared_amount' => 8700,
            'declared_card' => 3200,
            'declared_transfer' => 1140,
            'difference' => 0,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ], $attrs));
    }

    public function test_balanced_when_all_zero(): void
    {
        $v = $this->svc->build($this->shift());

        $this->assertSame('balanced', $v['status']);
        $this->assertSame(0.0, $v['total_diff']);
        $this->assertNull($v['detail']);
    }

    public function test_simple_cash_shortage(): void
    {
        $v = $this->svc->build($this->shift([
            'declared_amount' => 8650, 'difference' => -50,
        ]));

        $this->assertSame('net_off', $v['status']);
        $this->assertSame(-50.0, $v['total_diff']);
        $this->assertStringContainsString('Faltante total de $50.00', $v['headline']);
        $this->assertNull($v['detail']);
    }

    public function test_full_compensation_between_methods(): void
    {
        // Falta $50 en efectivo, sobra $50 en tarjeta → el neto cuadra.
        $v = $this->svc->build($this->shift([
            'declared_amount' => 8650, 'difference' => -50,
            'declared_card' => 3250, 'difference_card' => 50,
        ]));

        $this->assertSame('cross_balanced', $v['status']);
        $this->assertSame(0.0, $v['total_diff']);
        $this->assertStringContainsString('cuadra en total', $v['headline']);
        $this->assertStringContainsString('faltan $50.00 en efectivo', $v['detail']);
        $this->assertStringContainsString('sobran $50.00 en tarjeta', $v['detail']);
    }

    public function test_partial_compensation(): void
    {
        // Falta $80 en efectivo, sobra $30 en tarjeta → faltante real $50.
        $v = $this->svc->build($this->shift([
            'declared_amount' => 8620, 'difference' => -80,
            'declared_card' => 3230, 'difference_card' => 30,
        ]));

        $this->assertSame('net_off', $v['status']);
        $this->assertSame(-50.0, $v['total_diff']);
        $this->assertStringContainsString('Faltante total de $50.00', $v['headline']);
        $this->assertStringContainsString('faltan $80.00 en efectivo', $v['detail']);
        $this->assertStringContainsString('sobran $30.00 en tarjeta', $v['detail']);
    }

    public function test_method_with_movement_but_undeclared_is_included(): void
    {
        // Tarjeta no declarada (null) pero con movimiento: debe entrar en la
        // suma con declared = total y diff 0, para que el neto cuadre.
        $v = $this->svc->build($this->shift([
            'declared_card' => null, 'difference_card' => 0,
        ]));

        $card = collect($v['by_method'])->firstWhere('key', 'card');
        $this->assertNotNull($card);
        $this->assertSame(3200.0, $card['expected']);
        $this->assertSame(3200.0, $card['declared']);
        $this->assertTrue($card['declared_is_null']);
        // El total incluye la tarjeta: 8700 (cash) + 3200 + 1140 = 13040.
        $this->assertSame(13040.0, $v['expected_total']);
        $this->assertSame(13040.0, $v['declared_total']);
    }

    public function test_undeclared_when_nothing_declared(): void
    {
        $v = $this->svc->build($this->shift([
            'declared_amount' => null, 'declared_card' => null, 'declared_transfer' => null,
            'difference' => 0, 'difference_card' => 0, 'difference_transfer' => 0,
        ]));

        $this->assertSame('undeclared', $v['status']);
        $this->assertStringContainsString('sin conteo declarado', $v['headline']);
    }
}
