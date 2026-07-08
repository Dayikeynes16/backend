<?php

namespace Tests\Feature\Ai;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\SaleItem;
use App\Services\Ai\Assistant\ToolRegistry;
use App\Services\Ai\Assistant\Tools\CustomerDetailTool;
use App\Services\Ai\Assistant\Tools\ProductSalesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Tools de consulta avanzada (2026-07-08): ventas por producto con desglose de
 * precios y detalle de cliente con artículos. Siempre queries tipadas con
 * branch/tenant forzados — nunca SQL libre del modelo.
 */
class AdvancedQueryToolsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function saleWithItem(array $saleAttrs, string $product, float $qty, float $price): void
    {
        $sale = $this->makeCompletedSale(array_merge(['total' => $qty * $price, 'completed_at' => now()], $saleAttrs));
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => $product,
            'unit_type' => 'kg',
            'quantity' => $qty,
            'unit_price' => $price,
            'subtotal' => $qty * $price,
        ]);
    }

    public function test_product_sales_breaks_down_by_price(): void
    {
        $this->makeProduct(['name' => 'Pulpa negra', 'price' => 180, 'unit_type' => 'kg']);
        // 3 kg a $180 (lista) y 2 kg a $150 (precio preferencial).
        $this->saleWithItem([], 'Pulpa negra', 2, 180);
        $this->saleWithItem([], 'Pulpa negra', 1, 180);
        $this->saleWithItem([], 'Pulpa negra', 2, 150);
        // Ruido: otro producto y una cancelada.
        $this->saleWithItem([], 'Bistec', 1, 200);
        $this->saleWithItem(['status' => SaleStatus::Cancelled->value, 'cancelled_at' => now()], 'Pulpa negra', 9, 180);

        $tool = app(ProductSalesTool::class);
        $params = $tool->validate($this->adminSucursal, [
            'product_name' => 'pulpa', 'scope' => 'today', 'date_from' => null, 'date_to' => null, 'branch_name' => null,
        ]);
        $data = $tool->execute($this->adminSucursal, $params)->data;

        $this->assertTrue($data['found']);
        $this->assertEqualsWithDelta(5.0, $data['total_quantity'], 0.001);
        $this->assertEqualsWithDelta(3 * 180 + 2 * 150, $data['total_revenue'], 0.001);
        $this->assertCount(2, $data['price_breakdown']);
        $byPrice = collect($data['price_breakdown'])->keyBy('unit_price');
        $this->assertEqualsWithDelta(3.0, $byPrice[180]['quantity'], 0.001);
        $this->assertEqualsWithDelta(2.0, $byPrice[150]['quantity'], 0.001);
    }

    public function test_product_sales_is_branch_scoped_for_admin_sucursal(): void
    {
        $this->makeProduct(['name' => 'Pulpa negra', 'unit_type' => 'kg']);
        $this->saleWithItem([], 'Pulpa negra', 2, 180);
        $this->saleWithItem(['branch_id' => $this->secondBranch->id], 'Pulpa negra', 7, 180);

        $tool = app(ProductSalesTool::class);
        $params = $tool->validate($this->adminSucursal, [
            'product_name' => 'pulpa negra', 'scope' => 'today', 'date_from' => null, 'date_to' => null,
            'branch_name' => 'Sucursal 2', // debe ignorarse: branch forzado
        ]);
        $data = $tool->execute($this->adminSucursal, $params)->data;

        $this->assertEqualsWithDelta(2.0, $data['total_quantity'], 0.001);
    }

    public function test_customer_detail_returns_sales_with_items_and_debt(): void
    {
        $matias = Customer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'name' => 'Matias', 'status' => 'active',
        ]);
        $this->saleWithItem(['customer_id' => $matias->id, 'amount_paid' => 0, 'amount_pending' => 360, 'status' => SaleStatus::Active->value, 'completed_at' => null], 'Pulpa negra', 2, 180);

        $tool = app(CustomerDetailTool::class);
        $params = $tool->validate($this->cajero, ['customer_name' => 'el cliente matías', 'sales_limit' => null]);
        $this->assertSame($matias->id, $params['customer_id']);

        $result = $tool->execute($this->cajero, $params);
        $data = $result->data;

        $this->assertTrue($data['found']);
        $this->assertEqualsWithDelta(360.0, $data['total_owed'], 0.001);
        $this->assertCount(1, $data['sales']);
        $this->assertSame('Pulpa negra', $data['sales'][0]['items'][0]['product']);
        $this->assertEqualsWithDelta(180.0, $data['sales'][0]['items'][0]['unit_price'], 0.001);
        $this->assertStringContainsString('Pulpa negra', $result->summary);
    }

    public function test_customer_detail_is_branch_scoped_and_suggests_candidates(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->secondBranch->id,
            'name' => 'Matias Ajeno', 'status' => 'active',
        ]);

        $tool = app(CustomerDetailTool::class);
        $params = $tool->validate($this->cajero, ['customer_name' => 'Matias Ajeno', 'sales_limit' => 5]);

        // Cliente de otra sucursal: invisible para el cajero.
        $this->assertNull($params['customer_id']);
        $this->assertSame([], $params['customer_candidates']);
    }

    public function test_tools_registered_with_expected_roles(): void
    {
        $names = collect(app(ToolRegistry::class)->forUser($this->cajero))
            ->map(fn ($t) => $t->name());

        $this->assertTrue($names->contains('consultar_cliente_detalle'));
        $this->assertFalse($names->contains('consultar_ventas_producto'));
    }
}
