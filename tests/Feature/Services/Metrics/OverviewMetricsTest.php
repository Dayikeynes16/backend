<?php

namespace Tests\Feature\Services\Metrics;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Provider;
use App\Models\Purchase;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\OverviewMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class OverviewMetricsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        Carbon::setTestNow('2026-06-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): OverviewMetrics
    {
        return app(OverviewMetrics::class);
    }

    private function aprilRange(): DateRange
    {
        return DateRange::custom('2026-04-01', '2026-04-30');
    }

    private function makeSubcategory(): ExpenseSubcategory
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Servicios', 'status' => 'active']);

        return ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Luz',
            'status' => 'active',
        ]);
    }

    public function test_utilidad_uses_cogs_and_purchases_are_a_separate_cash_figure(): void
    {
        $product = $this->makeProduct();
        $this->makeCompletedSale(
            ['total' => 1000, 'completed_at' => Carbon::parse('2026-04-15 12:00:00')],
            [['product_id' => $product->id, 'product_name' => 'Costilla', 'quantity' => 10, 'unit_price' => 100, 'cost_price_at_sale' => 60]],
        );

        Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->makeSubcategory()->id, 'user_id' => $this->adminEmpresa->id,
            'concept' => 'Luz', 'amount' => 150, 'expense_at' => Carbon::parse('2026-04-10'),
        ]);

        $provider = Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne']);
        Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'provider_id' => $provider->id,
            'folio' => 'CMP-1', 'purchased_at' => Carbon::parse('2026-04-05'), 'status' => 'received',
            'subtotal' => 500, 'total' => 500, 'amount_pending' => 0,
        ]);

        $pnl = $this->service()->build($this->aprilRange(), null, $this->tenant->id, true)['pnl'];

        $this->assertEqualsWithDelta(1000, $pnl['ventas_netas'], 0.01);
        $this->assertEqualsWithDelta(600, $pnl['cmv'], 0.01);
        $this->assertEqualsWithDelta(400, $pnl['utilidad_bruta'], 0.01);
        $this->assertEqualsWithDelta(40, $pnl['margin_pct'], 0.01);
        $this->assertEqualsWithDelta(150, $pnl['gastos'], 0.01);
        // Utilidad neta = bruta(400) − gastos(150) = 250. Las compras (500) NO se restan.
        $this->assertEqualsWithDelta(250, $pnl['utilidad_neta'], 0.01);
        $this->assertEqualsWithDelta(100, $pnl['coverage']['pct'], 0.01);

        $compras = $this->service()->build($this->aprilRange(), null, $this->tenant->id, true)['compras'];
        $this->assertEqualsWithDelta(500, $compras, 0.01);
    }

    public function test_coverage_below_100_when_some_items_lack_cost(): void
    {
        $product = $this->makeProduct();
        $this->makeCompletedSale(
            ['completed_at' => Carbon::parse('2026-04-15 12:00:00')],
            [
                ['product_id' => $product->id, 'product_name' => 'Con costo', 'quantity' => 1, 'unit_price' => 100, 'cost_price_at_sale' => 60],
                // Sin product_id para que el hook de SaleItem no copie el costo del producto.
                ['product_id' => null, 'product_name' => 'Sin costo', 'quantity' => 1, 'unit_price' => 100, 'cost_price_at_sale' => null],
            ],
        );

        $pnl = $this->service()->build($this->aprilRange(), null, $this->tenant->id, true)['pnl'];

        $this->assertEqualsWithDelta(200, $pnl['ventas_netas'], 0.01);
        $this->assertEqualsWithDelta(40, $pnl['utilidad_bruta'], 0.01); // solo el item con costo
        $this->assertEqualsWithDelta(50, $pnl['coverage']['pct'], 0.01);
    }

    public function test_branch_comparison_present_for_empresa_absent_for_sucursal(): void
    {
        $empresa = $this->service()->build($this->aprilRange(), null, $this->tenant->id, true);
        $this->assertIsArray($empresa['branch_comparison']);
        $this->assertCount(2, $empresa['branch_comparison']); // branch + secondBranch

        $sucursal = $this->service()->build($this->aprilRange(), $this->branch->id, $this->tenant->id, false);
        $this->assertNull($sucursal['branch_comparison']);
    }

    public function test_low_margin_product_raises_alert(): void
    {
        $product = $this->makeProduct();
        $this->makeCompletedSale(
            ['completed_at' => Carbon::parse('2026-04-15 12:00:00')],
            [['product_id' => $product->id, 'product_name' => 'Arrachera', 'quantity' => 1, 'unit_price' => 100, 'cost_price_at_sale' => 95]],
        );

        $alerts = $this->service()->build($this->aprilRange(), null, $this->tenant->id, true)['alerts'];

        $this->assertContains('margen_bajo', array_column($alerts, 'type'));
    }

    public function test_overdue_receivable_raises_alert(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'name' => 'Cliente Deudor']);
        $this->makeCreditSale([
            'customer_id' => $customer->id,
            'total' => 500,
            'completed_at' => Carbon::parse('2026-04-15 12:00:00'), // ~64 días antes de "now" (2026-06-18)
        ]);

        $alerts = $this->service()->build($this->aprilRange(), null, $this->tenant->id, true)['alerts'];

        $this->assertContains('cobranza_vencida', array_column($alerts, 'type'));
    }

    public function test_empresa_index_endpoint_returns_resumen_data(): void
    {
        $this->actingAs($this->adminEmpresa);

        $data = $this->get(route('empresa.metricas.index', ['tenant' => $this->tenant->slug, 'from' => '2026-04-01', 'to' => '2026-04-30']))
            ->assertOk()
            ->viewData('page')['props']['data'];

        $this->assertArrayHasKey('pnl', $data);
        $this->assertArrayHasKey('utilidad_neta', $data['pnl']);
        $this->assertArrayHasKey('kpis', $data);
    }
}
