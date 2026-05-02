<?php

namespace Tests\Feature\Sucursal;

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
 * Feature tests del flujo asignar/desasignar cliente cuando la venta tiene
 * líneas con presentaciones. Cubre las dos correcciones críticas:
 *
 *   1. Precio preferencial guardado en CustomerProductPrice se interpreta
 *      como $/kg y debe convertirse a unit_price multiplicando por el
 *      contenido en kg de la presentación. Una presentación de 5 kg con
 *      preferencial $135/kg debe dar subtotal $675, no $135.
 *
 *   2. Al desasignar cliente, una línea con presentation_snapshot debe
 *      restaurarse al precio congelado en el snapshot, no al precio actual
 *      del producto base.
 */
class AssignCustomerPresentationTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $cheese;

    private ProductPresentation $fiveKilo;

    private ProductPresentation $halfKilo;

    private Customer $vip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Lácteos',
            'status' => 'active',
        ]);

        $this->cheese = Product::create([
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

        $this->fiveKilo = ProductPresentation::create([
            'product_id' => $this->cheese->id,
            'name' => '5k Quesos',
            'content' => 5.000,
            'unit' => 'kg',
            'price' => 750,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $this->halfKilo = ProductPresentation::create([
            'product_id' => $this->cheese->id,
            'name' => 'Medio queso',
            'content' => 0.500,
            'unit' => 'kg',
            'price' => 75,
            'sort_order' => 2,
            'status' => 'active',
        ]);

        $this->vip = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente VIP',
            'phone' => '5550000000',
            'status' => 'active',
        ]);

        CustomerProductPrice::create([
            'customer_id' => $this->vip->id,
            'product_id' => $this->cheese->id,
            'price' => 135, // $/kg preferencial
        ]);
    }

    public function test_assigning_customer_applies_preferential_price_per_kilo_to_five_kilo_presentation(): void
    {
        $sale = $this->makeSaleWithPresentation($this->fiveKilo, quantity: 1, unitPrice: 750);

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $this->vip->id,
            ])
            ->assertRedirect();

        $item = $sale->items()->first()->refresh();

        // $135/kg × 5 kg de contenido = $675 unit_price.
        $this->assertSame(675.0, (float) $item->unit_price, 'unit_price debe ser $675 (no $135).');
        $this->assertSame(675.0, (float) $item->subtotal, 'subtotal = unit_price × quantity = $675.');
        $this->assertSame(675.0, (float) $sale->refresh()->total);
    }

    public function test_assigning_customer_applies_preferential_price_to_two_half_kilos(): void
    {
        $sale = $this->makeSaleWithPresentation($this->halfKilo, quantity: 2, unitPrice: 75);

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $this->vip->id,
            ])
            ->assertRedirect();

        $item = $sale->items()->first()->refresh();

        // $135/kg × 0.5 kg = $67.50 unit_price. subtotal = 67.50 × 2 = $135.
        $this->assertSame(67.5, (float) $item->unit_price);
        $this->assertSame(135.0, (float) $item->subtotal);
        $this->assertSame(135.0, (float) $sale->refresh()->total);
    }

    public function test_unassigning_customer_restores_unit_price_from_presentation_snapshot(): void
    {
        $sale = $this->makeSaleWithPresentation($this->fiveKilo, quantity: 1, unitPrice: 750);

        // Subimos el precio del producto base después de la venta para
        // verificar que la restauración no usa el precio actual sino el
        // snapshot congelado al momento de la venta.
        $this->cheese->update(['price' => 9999]);

        // Primero asignamos cliente (modifica unit_price a $675).
        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $this->vip->id,
            ])
            ->assertRedirect();

        // Ahora desasignamos.
        $this->patch(route('sucursal.workbench.assign-customer', [$this->tenant->slug, $sale->id]), [
            'customer_id' => null,
        ])->assertRedirect();

        $item = $sale->items()->first()->refresh();

        // Snapshot.price era $750 al momento de la venta — eso es lo que debe restaurarse.
        $this->assertSame(750.0, (float) $item->unit_price);
        $this->assertSame(750.0, (float) $item->subtotal);
    }

    private function makeSaleWithPresentation(ProductPresentation $presentation, float $quantity, float $unitPrice): Sale
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
            'product_id' => $this->cheese->id,
            'presentation_id' => $presentation->id,
            'product_name' => $this->cheese->name.' - '.$presentation->name,
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
