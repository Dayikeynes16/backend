<?php

namespace Tests\Feature\Clientes;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\Customers\CustomerAssignmentPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El preview calcula qué pasaría al asignar un cliente a una venta, sin
 * escribir nada. De él depende que la confirmación aparezca solo cuando el
 * total cambia de verdad o la venta quedaría cobrada.
 *
 * Detalle que hace o rompe estos tests: el precio preferencial **solo aplica a
 * líneas de peso/volumen** (`SaleItemMath::isWeightOrVolume` mira
 * `quantity_unit`). Por eso el producto es `kg` y la línea lleva
 * `quantity_unit => 'kg'`.
 */
class CustomerAssignmentPreviewTest extends TestCase
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

    public function test_cliente_sin_precios_preferenciales_no_cambia_el_total(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);

        $preview = CustomerAssignmentPreview::for($sale, $customer);

        $this->assertFalse($preview['changes_total']);
        $this->assertSame(200.0, $preview['current_total']);
        $this->assertSame(200.0, $preview['new_total']);
        $this->assertFalse($preview['would_complete']);
        $this->assertSame([], $preview['skipped_piece_presentations']);
    }

    public function test_cliente_con_precio_preferencial_cambia_el_total(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);

        $this->givePreferentialPrice($customer, 80);

        $preview = CustomerAssignmentPreview::for($sale, $customer->fresh());

        $this->assertTrue($preview['changes_total']);
        $this->assertSame(200.0, $preview['current_total']);
        $this->assertSame(160.0, $preview['new_total']);
    }

    public function test_detecta_que_la_venta_quedaria_completada(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);
        $sale->update(['amount_paid' => 200, 'amount_pending' => 0]);

        $preview = CustomerAssignmentPreview::for($sale->fresh(), $customer);

        $this->assertTrue($preview['would_complete']);
    }

    public function test_una_venta_ya_completada_no_vuelve_a_completarse(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);
        $sale->update(['amount_paid' => 200, 'amount_pending' => 0, 'status' => SaleStatus::Completed]);

        $preview = CustomerAssignmentPreview::for($sale->fresh(), $customer);

        $this->assertFalse($preview['would_complete']);
    }

    public function test_venta_sin_pagos_no_se_completaria(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);

        $preview = CustomerAssignmentPreview::for($sale, $customer);

        $this->assertFalse($preview['would_complete']);
    }

    public function test_reporta_las_presentaciones_por_pieza_que_se_saltan(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $piece = $this->makeProduct(['unit_type' => 'pieza', 'name' => 'Chuleta empacada', 'price' => 50]);

        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);
        $sale->items()->create([
            'product_id' => $piece->id,
            'product_name' => $piece->name,
            'quantity' => 1,
            'quantity_unit' => 'piece',
            'unit_type' => 'pieza',
            'unit_price' => 50,
            'original_unit_price' => 50,
            'subtotal' => 50,
        ]);
        $sale->update(['total' => 250, 'amount_pending' => 250]);

        CustomerProductPrice::create([
            'customer_id' => $customer->id,
            'product_id' => $piece->id,
            'price' => 40,
        ]);

        $preview = CustomerAssignmentPreview::for($sale->fresh(), $customer->fresh());

        $this->assertSame(['Chuleta empacada'], $preview['skipped_piece_presentations']);
        // La línea por pieza conserva su subtotal: el preferencial no aplica.
        $this->assertSame(250.0, $preview['new_total']);
        $this->assertFalse($preview['changes_total']);
    }

    public function test_no_escribe_nada_en_la_base(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);
        $this->givePreferentialPrice($customer, 80);

        CustomerAssignmentPreview::for($sale, $customer->fresh());

        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame('200.00', $sale->fresh()->total);
        $this->assertSame('100.00', $sale->fresh()->items()->first()->unit_price);
    }

    public function test_venta_sin_lineas_da_total_cero(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 0,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        $preview = CustomerAssignmentPreview::for($sale, $customer);

        $this->assertSame(0.0, $preview['new_total']);
        $this->assertFalse($preview['changes_total']);
        $this->assertFalse($preview['would_complete']);
    }

    /**
     * El preview reimplementa el cálculo de `AssignCustomerToSale` para poder
     * mirarlo sin escribir. Si las dos lógicas divergen, la confirmación
     * mostraría cifras que no coinciden con lo que acaba pasando. Estos dos
     * tests son el pegamento entre ambas.
     */
    public function test_lo_que_predice_coincide_con_lo_que_hace_la_asignacion_real(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);
        $this->givePreferentialPrice($customer, 80);

        $preview = CustomerAssignmentPreview::for($sale, $customer->fresh());

        app(AssignCustomerToSale::class)->execute($sale, $customer->id, $this->branch->id);

        $this->assertSame($preview['new_total'], (float) $sale->fresh()->total);
    }

    public function test_predice_correctamente_el_paso_a_completada(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);
        $sale->update(['amount_paid' => 200, 'amount_pending' => 0]);
        $sale = $sale->fresh();

        $preview = CustomerAssignmentPreview::for($sale, $customer);
        $this->assertTrue($preview['would_complete']);

        app(AssignCustomerToSale::class)->execute($sale, $customer->id, $this->branch->id);

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
    }

    private function makeCustomer(string $phone): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente prueba',
            'phone' => $phone,
            'status' => 'active',
        ]);
    }

    /** `customer_product_prices` no tiene tenant_id: solo customer/product/price. */
    private function givePreferentialPrice(Customer $customer, float $price): void
    {
        CustomerProductPrice::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'price' => $price,
        ]);
    }

    private function makeSaleWithItem(float $unitPrice, float $quantity): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => $unitPrice * $quantity,
            'amount_paid' => 0,
            'amount_pending' => $unitPrice * $quantity,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        $sale->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => $quantity,
            'quantity_unit' => 'kg',
            'unit_type' => 'kg',
            'unit_price' => $unitPrice,
            'original_unit_price' => $unitPrice,
            'subtotal' => $unitPrice * $quantity,
        ]);

        return $sale->fresh();
    }
}
