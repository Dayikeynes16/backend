<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\OrderLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class UnlinkOrderTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        Event::fake([SaleUpdated::class]);
    }

    public function test_admin_can_unlink_when_sale_active_and_no_payments(): void
    {
        [$web, $scale] = $this->makeLinkedPair(deliveryFee: 70, itemsSubtotal: 200);

        $response = $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.workbench.unlink-order', [$this->tenant->slug, $scale->id]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $freshScale = $scale->fresh();
        $this->assertNull($freshScale->linked_order_id);
        $this->assertEquals(200, (float) $freshScale->total);
        $this->assertNull($freshScale->delivery_fee);
        $this->assertSame(SaleStatus::Pending, $web->fresh()->status);
    }

    public function test_unlink_blocked_when_payment_exists(): void
    {
        [$web, $scale] = $this->makeLinkedPair(deliveryFee: 0, itemsSubtotal: 100);
        Payment::create([
            'sale_id' => $scale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 50,
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.workbench.unlink-order', [$this->tenant->slug, $scale->id]));

        $response->assertSessionHas('error');
        $this->assertSame($web->id, $scale->fresh()->linked_order_id);
        $this->assertSame(SaleStatus::Fulfilled, $web->fresh()->status);
    }

    public function test_unlink_blocked_when_sale_is_not_linked(): void
    {
        $scale = $this->makeScaleSale(100);

        $response = $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.workbench.unlink-order', [$this->tenant->slug, $scale->id]));

        $response->assertSessionHas('error');
    }

    public function test_unlink_blocked_cross_branch(): void
    {
        $scale = $this->makeScaleSale(100, ['branch_id' => $this->secondBranch->id]);

        $response = $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.workbench.unlink-order', [$this->tenant->slug, $scale->id]));

        $response->assertStatus(403);
    }

    public function test_cajero_can_unlink_via_caja_route(): void
    {
        [$web, $scale] = $this->makeLinkedPair(deliveryFee: 70, itemsSubtotal: 200);

        $response = $this->actingAs($this->cajero)
            ->delete(route('caja.unlink-order', [$this->tenant->slug, $scale->id]));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertNull($scale->fresh()->linked_order_id);
    }

    /**
     * @return array{0: Sale, 1: Sale} [webOrder, scaleSale]
     */
    private function makeLinkedPair(float $deliveryFee, float $itemsSubtotal): array
    {
        $web = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-'.uniqid(),
            'payment_method' => 'cash',
            'total' => $itemsSubtotal + $deliveryFee,
            'amount_paid' => 0,
            'amount_pending' => $itemsSubtotal + $deliveryFee,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
            'delivery_fee' => $deliveryFee,
        ]);
        $scale = $this->makeScaleSale($itemsSubtotal);

        app(OrderLinkService::class)->link($scale, $web);

        return [$web->fresh(), $scale->fresh()];
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
