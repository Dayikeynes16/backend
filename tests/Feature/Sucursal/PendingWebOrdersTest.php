<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PendingWebOrdersTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_lists_only_pending_web_orders_of_user_branch(): void
    {
        $expected = $this->makeWebOrder(SaleStatus::Pending, $this->branch->id);
        $this->makeWebOrder(SaleStatus::Fulfilled, $this->branch->id);
        $this->makeWebOrder(SaleStatus::Cancelled, $this->branch->id);
        $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Active->value, 'branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.pending-web-orders', $this->tenant->slug));

        $response->assertOk();
        $orders = $response->json('orders');

        $this->assertCount(1, $orders);
        $this->assertSame($expected->id, $orders[0]['id']);
        $this->assertSame($expected->folio, $orders[0]['folio']);
    }

    public function test_excludes_orders_from_other_branches_same_tenant(): void
    {
        $this->makeWebOrder(SaleStatus::Pending, $this->branch->id);
        $this->makeWebOrder(SaleStatus::Pending, $this->secondBranch->id);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.pending-web-orders', $this->tenant->slug));

        $orders = $response->json('orders');
        $this->assertCount(1, $orders);
        foreach ($orders as $o) {
            $sale = Sale::find($o['id']);
            $this->assertSame($this->branch->id, $sale->branch_id);
        }
    }

    public function test_excludes_orders_from_other_tenants(): void
    {
        // Pedido del tenant actual
        $mine = $this->makeWebOrder(SaleStatus::Pending, $this->branch->id);

        // Crear otro tenant + sucursal + admin con un pedido pendiente
        $other = Tenant::create(['name' => 'Otro', 'slug' => 'otro', 'status' => 'active']);
        $otherBranch = Branch::create([
            'tenant_id' => $other->id, 'name' => 'B', 'address' => 'b', 'status' => 'active',
        ]);
        Sale::create([
            'tenant_id' => $other->id,
            'branch_id' => $otherBranch->id,
            'folio' => 'OTHER-1',
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.pending-web-orders', $this->tenant->slug));

        $orders = $response->json('orders');
        $this->assertCount(1, $orders);
        $this->assertSame($mine->id, $orders[0]['id']);
    }

    public function test_orders_returned_most_recent_first(): void
    {
        $old = $this->makeWebOrder(SaleStatus::Pending, $this->branch->id);
        DB::table('sales')->where('id', $old->id)->update(['created_at' => now()->subHour()]);
        $newer = $this->makeWebOrder(SaleStatus::Pending, $this->branch->id);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.pending-web-orders', $this->tenant->slug));

        $orders = $response->json('orders');
        $this->assertSame($newer->id, $orders[0]['id']);
        $this->assertSame($old->id, $orders[1]['id']);
    }

    public function test_items_preview_limited_to_three(): void
    {
        $order = $this->makeWebOrder(SaleStatus::Pending, $this->branch->id);
        for ($i = 1; $i <= 5; $i++) {
            SaleItem::create([
                'sale_id' => $order->id,
                'product_name' => "Producto {$i}",
                'unit_type' => 'kg',
                'quantity' => 1,
                'unit_price' => 100,
                'original_unit_price' => 100,
                'subtotal' => 100,
            ]);
        }

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.pending-web-orders', $this->tenant->slug));

        $orders = $response->json('orders');
        $this->assertSame(5, $orders[0]['items_count']);
        $this->assertCount(3, $orders[0]['items_preview']);
        $this->assertSame('Producto 1', $orders[0]['items_preview'][0]['product_name']);
    }

    public function test_payload_includes_required_fields(): void
    {
        $order = $this->makeWebOrder(SaleStatus::Pending, $this->branch->id, [
            'contact_name' => 'María',
            'contact_phone' => '+529931234567',
            'delivery_type' => 'delivery',
            'delivery_address' => 'Calle X 123',
            'delivery_fee' => 70,
            'total' => 470,
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.pending-web-orders', $this->tenant->slug));

        $payload = $response->json('orders.0');
        $this->assertSame($order->id, $payload['id']);
        $this->assertSame('María', $payload['contact_name']);
        $this->assertSame('+529931234567', $payload['contact_phone']);
        $this->assertSame('delivery', $payload['delivery_type']);
        $this->assertSame('Calle X 123', $payload['delivery_address']);
        $this->assertEquals(70, $payload['delivery_fee']);
        $this->assertEquals(470, $payload['total']);
    }

    public function test_cajero_route_returns_same_shape(): void
    {
        $this->makeWebOrder(SaleStatus::Pending, $this->branch->id);

        $response = $this->actingAs($this->cajero)
            ->getJson(route('caja.pending-web-orders', $this->tenant->slug));

        $response->assertOk();
        $this->assertCount(1, $response->json('orders'));
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $response = $this->getJson(route('sucursal.workbench.pending-web-orders', $this->tenant->slug));

        $response->assertStatus(401);
    }

    private function makeWebOrder(SaleStatus $status, int $branchId, array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'folio' => 'WEB-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => $status->value,
        ], $attrs));
    }

    private function makeSale(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'F-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ], $attrs));
    }
}
