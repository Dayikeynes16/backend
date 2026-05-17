<?php

namespace Tests\Unit\Services;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Exceptions\OrderLink\CrossBranchLinkException;
use App\Exceptions\OrderLink\IneligibleScaleSaleException;
use App\Exceptions\OrderLink\IneligibleWebOrderException;
use App\Exceptions\OrderLink\LockedScaleSaleException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\OrderLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class OrderLinkServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private OrderLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->service = app(OrderLinkService::class);
        Event::fake([SaleUpdated::class]);
    }

    public function test_link_marks_web_order_as_fulfilled_and_sets_link_on_scale_sale(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(itemsSubtotal: 200);

        $this->service->link($scale, $web);

        $this->assertSame(SaleStatus::Fulfilled, $web->fresh()->status);
        $this->assertSame($web->id, $scale->fresh()->linked_order_id);
    }

    public function test_link_sums_delivery_fee_to_scale_sale_total_and_recalculates_pending(): void
    {
        $web = $this->makeWebOrder(['delivery_fee' => 70]);
        $scale = $this->makeScaleSale(itemsSubtotal: 200, amountPaid: 50);

        $this->service->link($scale, $web);

        $fresh = $scale->fresh();
        $this->assertEquals(270, (float) $fresh->total);
        $this->assertEquals(220, (float) $fresh->amount_pending);
        $this->assertEquals(50, (float) $fresh->amount_paid);
    }

    public function test_link_copies_customer_and_contact_when_scale_sale_has_none(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Web',
            'phone' => '+529931234567',
            'status' => 'active',
        ]);

        $web = $this->makeWebOrder([
            'customer_id' => $customer->id,
            'contact_name' => 'Cliente Web',
            'contact_phone' => '+529931234567',
        ]);
        $scale = $this->makeScaleSale(itemsSubtotal: 100);

        $this->service->link($scale, $web);

        $fresh = $scale->fresh();
        $this->assertSame($customer->id, $fresh->customer_id);
        $this->assertSame('Cliente Web', $fresh->contact_name);
        $this->assertSame('+529931234567', $fresh->contact_phone);
    }

    public function test_link_preserves_existing_customer_and_contact_on_scale_sale(): void
    {
        $existing = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Mostrador',
            'phone' => '+5288888888',
            'status' => 'active',
        ]);
        $other = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Web',
            'phone' => '+5210000000',
            'status' => 'active',
        ]);

        $web = $this->makeWebOrder([
            'customer_id' => $other->id,
            'contact_name' => 'Cliente Web',
            'contact_phone' => '+5210000000',
        ]);
        $scale = $this->makeScaleSale(itemsSubtotal: 100, attrs: [
            'customer_id' => $existing->id,
            'contact_name' => 'Mostrador',
            'contact_phone' => '+5299999999',
        ]);

        $this->service->link($scale, $web);

        $fresh = $scale->fresh();
        $this->assertSame($existing->id, $fresh->customer_id);
        $this->assertSame('Mostrador', $fresh->contact_name);
        $this->assertSame('+5299999999', $fresh->contact_phone);
    }

    public function test_link_overwrites_delivery_fields_with_web_order_values(): void
    {
        $web = $this->makeWebOrder([
            'delivery_type' => 'delivery',
            'delivery_address' => 'Calle X 123',
            'delivery_lat' => 18.0012345,
            'delivery_lng' => -92.9450000,
            'delivery_distance_km' => 2.5,
            'delivery_fee' => 70,
        ]);
        $scale = $this->makeScaleSale(itemsSubtotal: 100, attrs: [
            'delivery_type' => 'pickup',
            'delivery_address' => 'no debería quedar',
        ]);

        $this->service->link($scale, $web);

        $fresh = $scale->fresh();
        $this->assertSame('delivery', $fresh->delivery_type);
        $this->assertSame('Calle X 123', $fresh->delivery_address);
        $this->assertEquals(2.5, (float) $fresh->delivery_distance_km);
        $this->assertEquals(70, (float) $fresh->delivery_fee);
    }

    public function test_link_broadcasts_sale_updated_for_both_sales(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(itemsSubtotal: 100);

        $this->service->link($scale, $web);

        Event::assertDispatchedTimes(SaleUpdated::class, 2);
        Event::assertDispatched(SaleUpdated::class, fn (SaleUpdated $e) => $e->sale->id === $scale->id);
        Event::assertDispatched(SaleUpdated::class, fn (SaleUpdated $e) => $e->sale->id === $web->id);
    }

    public function test_link_throws_when_scale_sale_is_web_origin(): void
    {
        $web1 = $this->makeWebOrder();
        $web2 = $this->makeWebOrder();

        $this->expectException(IneligibleScaleSaleException::class);
        $this->service->link($web1, $web2);
    }

    public function test_link_throws_when_scale_sale_already_linked(): void
    {
        $web1 = $this->makeWebOrder();
        $web2 = $this->makeWebOrder();
        $scale = $this->makeScaleSale(itemsSubtotal: 100, attrs: ['linked_order_id' => $web1->id]);

        $this->expectException(IneligibleScaleSaleException::class);
        $this->service->link($scale, $web2);
    }

    public function test_link_throws_when_scale_sale_not_active(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(itemsSubtotal: 100, attrs: ['status' => SaleStatus::Completed->value]);

        $this->expectException(IneligibleScaleSaleException::class);
        $this->service->link($scale, $web);
    }

    public function test_link_throws_when_web_order_not_pending(): void
    {
        $web = $this->makeWebOrder(['status' => SaleStatus::Cancelled->value]);
        $scale = $this->makeScaleSale(itemsSubtotal: 100);

        $this->expectException(IneligibleWebOrderException::class);
        $this->service->link($scale, $web);
    }

    public function test_link_throws_when_target_not_a_web_order(): void
    {
        $notWeb = $this->makeScaleSale(itemsSubtotal: 100, attrs: ['folio' => 'NOT-WEB']);
        $scale = $this->makeScaleSale(itemsSubtotal: 100);

        $this->expectException(IneligibleWebOrderException::class);
        $this->service->link($scale, $notWeb);
    }

    public function test_link_throws_on_cross_branch(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(itemsSubtotal: 100, attrs: ['branch_id' => $this->secondBranch->id]);

        $this->expectException(CrossBranchLinkException::class);
        $this->service->link($scale, $web);
    }

    public function test_unlink_reverts_web_order_to_pending_and_clears_link(): void
    {
        $web = $this->makeWebOrder(['delivery_fee' => 70]);
        $scale = $this->makeScaleSale(itemsSubtotal: 200);
        $this->service->link($scale, $web);
        Event::fake([SaleUpdated::class]);

        $this->service->unlink($scale->fresh());

        $this->assertNull($scale->fresh()->linked_order_id);
        $this->assertSame(SaleStatus::Pending, $web->fresh()->status);
        $this->assertEquals(200, (float) $scale->fresh()->total);
        $this->assertNull($scale->fresh()->delivery_type);
        $this->assertNull($scale->fresh()->delivery_fee);
    }

    public function test_unlink_keeps_customer_and_contact_on_scale_sale(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Web',
            'phone' => '+5210000000',
            'status' => 'active',
        ]);
        $web = $this->makeWebOrder([
            'customer_id' => $customer->id,
            'contact_name' => 'Cliente Web',
            'contact_phone' => '+5210000000',
        ]);
        $scale = $this->makeScaleSale(itemsSubtotal: 100);
        $this->service->link($scale, $web);

        $this->service->unlink($scale->fresh());

        $fresh = $scale->fresh();
        $this->assertSame($customer->id, $fresh->customer_id);
        $this->assertSame('Cliente Web', $fresh->contact_name);
        $this->assertSame('+5210000000', $fresh->contact_phone);
    }

    public function test_unlink_throws_when_sale_has_payments(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(itemsSubtotal: 100);
        $this->service->link($scale, $web);
        Payment::create([
            'sale_id' => $scale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 50,
        ]);

        $this->expectException(LockedScaleSaleException::class);
        $this->service->unlink($scale->fresh());
    }

    public function test_unlink_throws_when_sale_not_active(): void
    {
        $web = $this->makeWebOrder();
        $scale = $this->makeScaleSale(itemsSubtotal: 100);
        $this->service->link($scale, $web);
        $scale->update(['status' => SaleStatus::Completed->value]);

        $this->expectException(LockedScaleSaleException::class);
        $this->service->unlink($scale->fresh());
    }

    public function test_unlink_throws_when_sale_not_linked(): void
    {
        $scale = $this->makeScaleSale(itemsSubtotal: 100);

        $this->expectException(IneligibleScaleSaleException::class);
        $this->service->unlink($scale);
    }

    private function makeWebOrder(array $attrs = []): Sale
    {
        $sale = Sale::create(array_merge([
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

        return $sale;
    }

    private function makeScaleSale(float $itemsSubtotal, float $amountPaid = 0, array $attrs = []): Sale
    {
        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'API-'.uniqid(),
            'payment_method' => 'cash',
            'total' => $itemsSubtotal,
            'amount_paid' => $amountPaid,
            'amount_pending' => $itemsSubtotal - $amountPaid,
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
