<?php

namespace Tests\Feature\Services;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\CustomerGlobalPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerGlobalPaymentServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Customer $customer;

    private CustomerGlobalPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Fiado',
            'status' => 'active',
        ]);

        $this->service = app(CustomerGlobalPaymentService::class);
    }

    private function pendingSale(float $total, string $createdAt): Sale
    {
        return $this->makeCompletedSale([
            'customer_id' => $this->customer->id,
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'status' => SaleStatus::Active->value,
            'completed_at' => null,
            'created_at' => $createdAt,
        ]);
    }

    public function test_preview_distributes_fifo_oldest_first(): void
    {
        $old = $this->pendingSale(300, '2026-06-01 10:00:00');
        $new = $this->pendingSale(500, '2026-06-15 10:00:00');

        $preview = $this->service->preview($this->customer, 400.0, 'cash');

        $this->assertEqualsWithDelta(800.0, $preview['total_pending'], 0.001);
        $this->assertEqualsWithDelta(400.0, $preview['amount_to_apply'], 0.001);
        $this->assertEqualsWithDelta(0.0, $preview['change_given'], 0.001);
        $this->assertEqualsWithDelta(400.0, $preview['remaining_debt'], 0.001);

        $this->assertCount(2, $preview['sales']);
        $this->assertSame($old->id, $preview['sales'][0]['sale_id']);
        $this->assertEqualsWithDelta(300.0, $preview['sales'][0]['amount_to_apply'], 0.001);
        $this->assertEqualsWithDelta(0.0, $preview['sales'][0]['remaining_after'], 0.001);
        $this->assertSame($new->id, $preview['sales'][1]['sale_id']);
        $this->assertEqualsWithDelta(100.0, $preview['sales'][1]['amount_to_apply'], 0.001);
        $this->assertEqualsWithDelta(400.0, $preview['sales'][1]['remaining_after'], 0.001);
    }

    public function test_preview_returns_change_only_for_cash(): void
    {
        $this->pendingSale(200, '2026-06-01 10:00:00');

        $cash = $this->service->preview($this->customer, 500.0, 'cash');
        $this->assertEqualsWithDelta(300.0, $cash['change_given'], 0.001);
        $this->assertEqualsWithDelta(200.0, $cash['amount_to_apply'], 0.001);

        $transfer = $this->service->preview($this->customer, 500.0, 'transfer');
        $this->assertEqualsWithDelta(0.0, $transfer['change_given'], 0.001);
    }

    public function test_apply_matches_preview_distribution(): void
    {
        $old = $this->pendingSale(300, '2026-06-01 10:00:00');
        $new = $this->pendingSale(500, '2026-06-15 10:00:00');

        $preview = $this->service->preview($this->customer, 400.0, 'cash');
        $result = $this->service->apply($this->customer, $this->adminSucursal, [
            'amount_received' => 400.0,
            'method' => 'cash',
        ]);

        $cp = $result['customer_payment'];
        $this->assertEqualsWithDelta($preview['amount_to_apply'], (float) $cp->amount_applied, 0.001);
        $this->assertSame(2, $cp->sales_affected_count);
        $this->assertStringStartsWith('CG-', $cp->folio);

        $this->assertSame(
            collect($preview['sales'])->pluck('amount_to_apply', 'sale_id')->all(),
            collect($result['applied'])->pluck('amount', 'sale_id')->map(fn ($v) => (float) $v)->all(),
        );

        $this->assertSame(2, Payment::where('customer_payment_id', $cp->id)->count());
        $this->assertEqualsWithDelta(0.0, (float) $old->fresh()->amount_pending, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $new->fresh()->amount_pending, 0.001);
        $this->assertSame(SaleStatus::Completed, $old->fresh()->status);
    }

    public function test_apply_rejects_non_cash_overpayment(): void
    {
        $this->pendingSale(200, '2026-06-01 10:00:00');

        try {
            $this->service->apply($this->customer, $this->adminSucursal, [
                'amount_received' => 500.0,
                'method' => 'transfer',
            ]);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_apply_aborts_without_pending_sales(): void
    {
        try {
            $this->service->apply($this->customer, $this->adminSucursal, [
                'amount_received' => 100.0,
                'method' => 'cash',
            ]);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, CustomerPayment::count());
    }
}
