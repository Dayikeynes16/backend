<?php

namespace Tests\Feature\Ventas;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\Customers\CustomerAssignmentPreview;
use App\Services\SaleItemEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El total de una venta a domicilio es `suma de líneas + costo de envío`.
 *
 * `OrderLinkService` siempre lo calculó así, pero `AssignCustomerToSale` y
 * `SaleItemEditor` sumaban solo las líneas, de modo que asignar un cliente o
 * tocar una línea **borraba el costo de envío del total**. Estos tests fijan
 * que los tres coincidan.
 */
class SaleTotalsWithDeliveryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->product = $this->makeProduct(['unit_type' => 'kg', 'price' => 100]);
    }

    public function test_asignar_cliente_conserva_el_costo_de_envio(): void
    {
        $sale = $this->makeDeliverySale(lineSubtotal: 200, deliveryFee: 30);
        $customer = $this->makeCustomer();

        app(AssignCustomerToSale::class)->execute($sale, $customer->id, $this->branch->id);

        $this->assertSame('230.00', $sale->fresh()->total);
    }

    public function test_desasignar_cliente_conserva_el_costo_de_envio(): void
    {
        $sale = $this->makeDeliverySale(lineSubtotal: 200, deliveryFee: 30);
        $customer = $this->makeCustomer();

        app(AssignCustomerToSale::class)->execute($sale, $customer->id, $this->branch->id);
        app(AssignCustomerToSale::class)->execute($sale->fresh(), null, $this->branch->id);

        $this->assertSame('230.00', $sale->fresh()->total);
    }

    public function test_el_pendiente_tambien_incluye_el_envio(): void
    {
        $sale = $this->makeDeliverySale(lineSubtotal: 200, deliveryFee: 30);
        $customer = $this->makeCustomer();

        app(AssignCustomerToSale::class)->execute($sale, $customer->id, $this->branch->id);

        $this->assertSame('230.00', $sale->fresh()->amount_pending);
    }

    public function test_agregar_una_linea_conserva_el_costo_de_envio(): void
    {
        $sale = $this->makeDeliverySale(lineSubtotal: 200, deliveryFee: 30);

        app(SaleItemEditor::class)->add($sale, [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ], 'prueba', $this->adminSucursal);

        $this->assertSame('330.00', $sale->fresh()->total);
    }

    public function test_borrar_una_linea_conserva_el_costo_de_envio(): void
    {
        $sale = $this->makeDeliverySale(lineSubtotal: 200, deliveryFee: 30);
        $extra = app(SaleItemEditor::class)->add($sale, [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ], 'prueba', $this->adminSucursal);

        app(SaleItemEditor::class)->remove($sale->fresh(), $extra, 'prueba', $this->adminSucursal);

        $this->assertSame('230.00', $sale->fresh()->total);
    }

    public function test_el_preview_refleja_el_total_con_envio(): void
    {
        $sale = $this->makeDeliverySale(lineSubtotal: 200, deliveryFee: 30);
        $customer = $this->makeCustomer();

        $preview = CustomerAssignmentPreview::for($sale, $customer);

        $this->assertSame(230.0, $preview['new_total']);
        $this->assertFalse($preview['changes_total']);
    }

    public function test_una_venta_sin_envio_no_cambia_de_comportamiento(): void
    {
        $sale = $this->makeDeliverySale(lineSubtotal: 200, deliveryFee: null);
        $customer = $this->makeCustomer();

        app(AssignCustomerToSale::class)->execute($sale, $customer->id, $this->branch->id);

        $this->assertSame('200.00', $sale->fresh()->total);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente a domicilio',
            'phone' => '9931234567',
            'status' => 'active',
        ]);
    }

    private function makeDeliverySale(float $lineSubtotal, ?float $deliveryFee): Sale
    {
        $total = $lineSubtotal + (float) ($deliveryFee ?? 0);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
            'delivery_type' => $deliveryFee === null ? null : 'delivery',
            'delivery_fee' => $deliveryFee,
        ]);

        $sale->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 2,
            'quantity_unit' => 'kg',
            'unit_type' => 'kg',
            'unit_price' => $lineSubtotal / 2,
            'original_unit_price' => $lineSubtotal / 2,
            'subtotal' => $lineSubtotal,
        ]);

        return $sale->fresh();
    }
}
