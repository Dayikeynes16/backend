<?php

namespace Tests\Feature\Caja;

use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El cajero puede asignar/desasignar clientes existentes en su Workbench:
 *  - aplica precio preferencial ($/kg) a presentaciones con peso
 *  - recalcula totals/pendiente
 *  - limpia contact_phone manual cuando aplica
 *  - bloquea sobre ventas Cancelled
 *  - 403 cross-branch
 *
 * El cajero NO tiene CRUD de clientes — solo seleccionar uno existente.
 */
class AssignCustomerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_cajero_can_assign_customer_with_preferential_price_to_weight_presentation(): void
    {
        [$product, $presentation] = $this->makeWeightProductWithPresentation();
        $customer = $this->makeCustomerWithPreferentialPrice($product, 135); // $135/kg

        $sale = $this->makeSaleWithPresentation($product, $presentation, quantity: 1, unitPrice: 750);

        $this->actingAs($this->cajero)
            ->patch(route('caja.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect();

        $item = $sale->items()->first()->refresh();

        // $135/kg × 5 kg = $675.
        $this->assertSame(675.0, (float) $item->unit_price);
        $this->assertSame(675.0, (float) $item->subtotal);
        $this->assertSame(675.0, (float) $sale->refresh()->total);
        $this->assertSame($customer->id, $sale->customer_id);
    }

    public function test_cajero_assigning_customer_clears_contact_phone_on_pos_sale(): void
    {
        $customer = $this->makeBasicCustomer();
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
            'contact_phone' => '+529998887766',
        ]);

        $this->actingAs($this->cajero)
            ->patch(route('caja.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect();

        $this->assertNull($sale->fresh()->contact_phone);
    }

    public function test_cajero_assigning_customer_keeps_contact_phone_on_web_origin(): void
    {
        $customer = $this->makeBasicCustomer();
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending,
            'contact_phone' => '+529998887766',
        ]);

        $this->actingAs($this->cajero)
            ->patch(route('caja.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect();

        $this->assertSame('+529998887766', $sale->fresh()->contact_phone);
    }

    public function test_cajero_cannot_assign_customer_to_cancelled_sale(): void
    {
        $customer = $this->makeBasicCustomer();
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Cancelled,
        ]);

        $this->actingAs($this->cajero)
            ->patch(route('caja.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($sale->fresh()->customer_id);
    }

    public function test_cajero_cannot_assign_customer_in_other_branch(): void
    {
        $customer = $this->makeBasicCustomer();
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        $this->actingAs($this->cajero)
            ->patch(route('caja.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertForbidden();

        $this->assertNull($sale->fresh()->customer_id);
    }

    public function test_cajero_can_unassign_customer_and_restores_snapshot_price(): void
    {
        [$product, $presentation] = $this->makeWeightProductWithPresentation();
        $customer = $this->makeCustomerWithPreferentialPrice($product, 135);

        $sale = $this->makeSaleWithPresentation($product, $presentation, quantity: 1, unitPrice: 750);

        // Subimos el precio del producto base para verificar que la restauración
        // usa el snapshot, no el precio actual.
        $product->update(['price' => 9999]);

        // Asignar.
        $this->actingAs($this->cajero)
            ->patch(route('caja.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect();

        // Desasignar.
        $this->actingAs($this->cajero)
            ->patch(route('caja.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => null,
            ])
            ->assertRedirect();

        $item = $sale->items()->first()->refresh();
        $this->assertSame(750.0, (float) $item->unit_price);
        $this->assertSame(750.0, (float) $item->subtotal);
        $this->assertNull($sale->refresh()->customer_id);
    }

    private function makeWeightProductWithPresentation(): array
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Lácteos',
            'status' => 'active',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'Queso',
            'price' => 150,
            'cost_price' => 120,
            'unit_type' => 'kg',
            'sale_mode' => 'both',
            'status' => 'active',
        ]);

        $presentation = ProductPresentation::create([
            'product_id' => $product->id,
            'name' => '5k Quesos',
            'content' => 5.000,
            'unit' => 'kg',
            'price' => 750,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        return [$product, $presentation];
    }

    private function makeCustomerWithPreferentialPrice(Product $product, float $pricePerBaseUnit): Customer
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente VIP',
            'phone' => '5550000000',
            'status' => 'active',
        ]);

        CustomerProductPrice::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => $pricePerBaseUnit,
        ]);

        return $customer;
    }

    private function makeBasicCustomer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Test',
            'phone' => '5511112222',
            'status' => 'active',
        ]);
    }

    private function makeSaleWithPresentation(Product $product, ProductPresentation $presentation, float $quantity, float $unitPrice): Sale
    {
        $subtotal = round($unitPrice * $quantity, 2);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'S-'.uniqid(),
            'total' => $subtotal,
            'amount_paid' => 0,
            'amount_pending' => $subtotal,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'presentation_id' => $presentation->id,
            'product_name' => $product->name.' - '.$presentation->name,
            'unit_type' => 'unit',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'original_unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'presentation_snapshot' => [
                'id' => $presentation->id,
                'name' => $presentation->name,
                'content' => (float) $presentation->content,
                'unit' => $presentation->unit,
                'price' => (float) $presentation->price,
            ],
            'sale_mode_at_sale' => 'presentation',
            'quantity_unit' => 'unit',
        ]);

        return $sale;
    }
}
