<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class LinkOrderTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        Event::fake([SaleUpdated::class]);
    }

    public function test_admin_can_link_scale_sale_to_pending_web_order(): void
    {
        $web = $this->makeWebOrder(['delivery_fee' => 70]);
        $scale = $this->makeScaleSale(200);

        $response = $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]), [
                'order_id' => $web->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $freshScale = $scale->fresh();
        $this->assertSame($web->id, $freshScale->linked_order_id);
        $this->assertEquals(270, (float) $freshScale->total);
        $this->assertSame(SaleStatus::Fulfilled, $web->fresh()->status);
    }

    public function test_link_broadcasts_sale_updated_for_both_sales(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(100);

        $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]), [
                'order_id' => $web->id,
            ]);

        Event::assertDispatchedTimes(SaleUpdated::class, 2);
    }

    public function test_link_returns_error_when_scale_sale_already_linked(): void
    {
        $web1 = $this->makeWebOrder();
        $web2 = $this->makeWebOrder();
        $scale = $this->makeScaleSale(100, ['linked_order_id' => $web1->id]);

        $response = $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]), [
                'order_id' => $web2->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame($web1->id, $scale->fresh()->linked_order_id);
    }

    public function test_link_returns_error_when_web_order_not_pending(): void
    {
        $web = $this->makeWebOrder(['status' => SaleStatus::Cancelled->value]);
        $scale = $this->makeScaleSale(100);

        $response = $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]), [
                'order_id' => $web->id,
            ]);

        $response->assertSessionHas('error');
        $this->assertNull($scale->fresh()->linked_order_id);
    }

    public function test_link_validation_rejects_nonexistent_order_id(): void
    {
        $scale = $this->makeScaleSale(100);

        $response = $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]), [
                'order_id' => 999999,
            ]);

        $response->assertSessionHasErrors('order_id');
    }

    public function test_link_blocks_cross_branch_scale_sale(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(100, ['branch_id' => $this->secondBranch->id]);

        $response = $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]), [
                'order_id' => $web->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_cajero_can_link_via_caja_route(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(100);

        $response = $this->actingAs($this->cajero)
            ->post(route('caja.link-order', [$this->tenant->slug, $scale->id]), [
                'order_id' => $web->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame($web->id, $scale->fresh()->linked_order_id);
    }

    public function test_unauthenticated_request_is_blocked(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(100);

        $response = $this->post(route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]), [
            'order_id' => $web->id,
        ]);

        $response->assertRedirect(); // redirected to login
    }

    private function makeWebOrder(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ], $attrs));
    }

    private function makeScaleSale(float $itemsSubtotal, array $attrs = []): Sale
    {
        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'API-'.uniqid(),
            'payment_method' => 'cash',
            'total' => $itemsSubtotal,
            'amount_paid' => 0,
            'amount_pending' => $itemsSubtotal,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ], $attrs));

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Carne',
            'unit_type' => 'kg',
            'quantity' => 1,
            'unit_price' => $itemsSubtotal,
            'original_unit_price' => $itemsSubtotal,
            'subtotal' => $itemsSubtotal,
        ]);

        return $sale;
    }
}
