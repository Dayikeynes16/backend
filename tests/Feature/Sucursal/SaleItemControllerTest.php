<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleItemControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function product(array $attrs = []): Product
    {
        return $this->makeProduct(array_merge(['price' => 100, 'sale_mode' => 'piece', 'unit_type' => 'pieza'], $attrs));
    }

    private function activeSale(): Sale
    {
        $product = $this->product();
        $sale = $this->makeCompletedSale([
            'status' => SaleStatus::Active->value,
            'completed_at' => null,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'total' => 100,
            'locked_by' => $this->adminSucursal->id,
            'locked_at' => now(),
        ], [['product_id' => $product->id, 'unit_price' => 100, 'quantity' => 1]]);

        return $sale->refresh();
    }

    public function test_admin_can_add_item_via_endpoint(): void
    {
        $sale = $this->activeSale();
        $other = $this->product(['name' => 'Costilla', 'price' => 50]);

        $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.items.store', [$this->tenant->slug, $sale->id]), [
                'product_id' => $other->id,
                'quantity' => 2,
                'unit_price' => 50,
                'reason' => 'Cliente pidió más',
            ])
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame(200.0, (float) $sale->total);
        $this->assertSame(2, $sale->items()->count());
        $this->assertSame(SaleItemChange::EVENT_ADDED, SaleItemChange::where('sale_id', $sale->id)->latest()->first()->event);
    }

    public function test_cajero_cannot_add_item(): void
    {
        $sale = $this->activeSale();
        $product = $this->product();

        $this->actingAs($this->cajero)
            ->post(route('sucursal.workbench.items.store', [$this->tenant->slug, $sale->id]), [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_update_item_and_total_recalculates(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.items.update', [$this->tenant->slug, $sale->id, $item->id]), [
                'quantity' => 3,
                'unit_price' => 80,
            ])
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame(240.0, (float) $sale->total);
    }

    public function test_update_with_same_values_flashes_error(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.items.update', [$this->tenant->slug, $sale->id, $item->id]), [
                'quantity' => 1,
                'unit_price' => 100,
            ])
            ->assertSessionHas('error');
    }

    public function test_destroy_requires_reason(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.workbench.items.destroy', [$this->tenant->slug, $sale->id, $item->id]))
            ->assertSessionHasErrors('reason');
    }

    public function test_admin_can_remove_item_with_reason(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.workbench.items.destroy', [$this->tenant->slug, $sale->id, $item->id]), [
                'reason' => 'Producto agotado',
            ])
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->total);
        $this->assertTrue(SaleItem::withTrashed()->find($item->id)->trashed());
    }

    public function test_completed_sale_rejects_edit_with_helpful_message(): void
    {
        $sale = $this->activeSale();
        $sale->update(['status' => SaleStatus::Completed, 'completed_at' => now()]);
        $item = $sale->items->first();

        $response = $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.items.update', [$this->tenant->slug, $sale->id, $item->id]), [
                'quantity' => 2,
                'unit_price' => 100,
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('cobrada', session('error'));
    }

    public function test_branch_required_mode_enforces_reason_on_add(): void
    {
        $this->branch->update(['sale_item_edit_reason_mode' => 'required']);
        $sale = $this->activeSale();
        $product = $this->product(['name' => 'X']);

        $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.items.store', [$this->tenant->slug, $sale->id]), [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 50,
            ])
            ->assertSessionHasErrors('reason');
    }
}
