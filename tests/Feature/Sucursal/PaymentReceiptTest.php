<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->branch->forceFill([
            'payment_receipts_enabled' => true,
            'payment_receipts_required' => false,
        ])->save();
    }

    private function makeSaleWithTransferPayment(): array
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'transfer',
            'status' => SaleStatus::Active,
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Producto',
            'unit_type' => 'piece',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
        ]);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'transfer',
            'amount' => 100,
        ]);

        return [$sale, $payment];
    }

    public function test_receipt_model_links_to_payment(): void
    {
        [, $payment] = $this->makeSaleWithTransferPayment();

        $receipt = PaymentReceipt::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'uploaded_by' => $this->cajero->id,
            'original_name' => 'comprobante.jpg',
            'path' => 'tenants/x/payment_receipts/p-1/a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1234,
        ]);

        $this->assertSame(1, $payment->receipts()->count());
        $this->assertSame($receipt->id, $payment->receipts()->first()->id);
    }
}
