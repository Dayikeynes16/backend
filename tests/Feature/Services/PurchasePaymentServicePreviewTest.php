<?php

namespace Tests\Feature\Services;

use App\Models\Provider;
use App\Models\Purchase;
use App\Services\PurchasePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchasePaymentServicePreviewTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Provider $provider;

    private PurchasePaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->provider = Provider::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Carnes del Norte',
            'type' => 'mayorista_carne',
            'status' => 'active',
        ]);

        $this->service = app(PurchasePaymentService::class);
    }

    private function makePurchase(float $total, string $purchasedAt, array $attrs = []): Purchase
    {
        return Purchase::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $this->provider->id,
            'folio' => 'CMP-'.uniqid(),
            'purchased_at' => $purchasedAt,
            'status' => 'received',
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
        ], $attrs));
    }

    public function test_preview_distributes_fifo_oldest_first(): void
    {
        $old = $this->makePurchase(3000, '2026-06-01');
        $new = $this->makePurchase(4000, '2026-06-15');

        $preview = $this->service->previewAccountPayment($this->provider, 5000.0);

        $this->assertEqualsWithDelta(7000.0, $preview['total_pending'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $preview['amount_to_apply'], 0.001);
        $this->assertEqualsWithDelta(0.0, $preview['surplus'], 0.001);

        $this->assertCount(2, $preview['purchases']);
        $this->assertSame($old->id, $preview['purchases'][0]['purchase_id']);
        $this->assertEqualsWithDelta(3000.0, $preview['purchases'][0]['amount_to_apply'], 0.001);
        $this->assertSame($new->id, $preview['purchases'][1]['purchase_id']);
        $this->assertEqualsWithDelta(2000.0, $preview['purchases'][1]['amount_to_apply'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $preview['purchases'][1]['remaining_after'], 0.001);
    }

    public function test_preview_reports_surplus_when_amount_exceeds_debt(): void
    {
        $this->makePurchase(1000, '2026-06-01');

        $preview = $this->service->previewAccountPayment($this->provider, 1500.0);

        $this->assertEqualsWithDelta(1000.0, $preview['amount_to_apply'], 0.001);
        $this->assertEqualsWithDelta(500.0, $preview['surplus'], 0.001);
    }

    public function test_preview_respects_branch_filter(): void
    {
        $this->makePurchase(1000, '2026-06-01');
        $this->makePurchase(2000, '2026-06-02', ['branch_id' => $this->secondBranch->id]);

        $preview = $this->service->previewAccountPayment($this->provider, 5000.0, $this->branch->id);

        $this->assertEqualsWithDelta(1000.0, $preview['total_pending'], 0.001);
        $this->assertCount(1, $preview['purchases']);
    }

    public function test_apply_matches_preview_distribution(): void
    {
        $this->makePurchase(3000, '2026-06-01');
        $this->makePurchase(4000, '2026-06-15');

        $preview = $this->service->previewAccountPayment($this->provider, 5000.0);
        $created = $this->service->applyAccountPayment($this->provider, [
            'amount' => 5000.0,
            'payment_method' => 'transfer',
        ]);

        $this->assertCount(2, $created);
        $this->assertSame(
            collect($preview['purchases'])->pluck('amount_to_apply', 'purchase_id')->all(),
            collect($created)->pluck('amount', 'purchase_id')->map(fn ($v) => (float) $v)->all(),
        );
    }
}
