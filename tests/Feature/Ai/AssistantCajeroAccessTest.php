<?php

namespace Tests\Feature\Ai;

use App\Models\CashRegisterShift;
use App\Services\Ai\Assistant\ToolRegistry;
use App\Services\Ai\Assistant\Tools\PrepareExpenseDraftTool;
use App\Services\Ai\Assistant\Tools\PreparePurchaseDraftTool;
use App\Services\Ai\Assistant\Tools\ShiftStatusTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * F5 (D5): el cajero entra al asistente con un juego de herramientas operativo
 * de caja — sin resúmenes de ventas ni métricas; gasto/compra solo con los
 * toggles de sucursal.
 */
class AssistantCajeroAccessTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_cajero_can_view_mini_app(): void
    {
        $this->actingAs($this->cajero);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Asistente/App'));
    }

    public function test_cajero_toolset_is_operational_only(): void
    {
        $names = collect(app(ToolRegistry::class)->forUser($this->cajero))
            ->map(fn ($tool) => $tool->name());

        $this->assertTrue($names->contains('consultar_turnos'));
        $this->assertTrue($names->contains('consultar_clientes'));
        $this->assertTrue($names->contains('preparar_cobro_cliente'));
        $this->assertTrue($names->contains('preparar_retiro_caja'));

        $this->assertFalse($names->contains('consultar_ventas'));
        $this->assertFalse($names->contains('consultar_compras'));
        $this->assertFalse($names->contains('consultar_cuentas_por_pagar'));
        $this->assertFalse($names->contains('preparar_cambio_precio'));
        $this->assertFalse($names->contains('preparar_pago_proveedor_cuenta'));
    }

    public function test_expense_and_purchase_tools_gated_by_branch_toggles(): void
    {
        $this->branch->update(['cashier_expenses_enabled' => false, 'cashier_purchases_enabled' => false]);
        $this->assertFalse(app(PrepareExpenseDraftTool::class)->authorize($this->cajero, []));
        $this->assertFalse(app(PreparePurchaseDraftTool::class)->authorize($this->cajero, []));

        $this->branch->update(['cashier_expenses_enabled' => true, 'cashier_purchases_enabled' => true]);
        $this->assertTrue(app(PrepareExpenseDraftTool::class)->authorize($this->cajero, []));
        $this->assertTrue(app(PreparePurchaseDraftTool::class)->authorize($this->cajero, []));

        // Los admins no dependen del toggle.
        $this->branch->update(['cashier_expenses_enabled' => false]);
        $this->assertTrue(app(PrepareExpenseDraftTool::class)->authorize($this->adminSucursal, []));
    }

    public function test_cajero_shift_status_only_shows_own_shifts(): void
    {
        CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 100,
        ]);
        CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'opened_at' => now()->subHours(2),
            'opening_amount' => 200,
        ]);

        $tool = app(ShiftStatusTool::class);
        $params = $tool->validate($this->cajero, ['branch_name' => null, 'recent_limit' => 5]);
        $result = $tool->execute($this->cajero, $params);

        $this->assertCount(1, $result->data['open_shifts']);
        $this->assertSame($this->cajero->name, $result->data['open_shifts'][0]['cashier']);
    }
}
