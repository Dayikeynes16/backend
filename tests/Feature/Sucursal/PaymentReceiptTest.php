<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function makeActiveSale(): Sale
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

        return $sale;
    }

    private function openShiftFor(User $user): CashRegisterShift
    {
        return CashRegisterShift::create([
            'user_id' => $user->id,
            'branch_id' => $this->branch->id,
            'tenant_id' => $this->tenant->id,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);
    }

    private function payUrl(Sale $sale): string
    {
        // El actor de estos tests es $this->cajero, cuyo rol solo tiene acceso
        // al prefijo /caja (route:list: "caja.payment.store" -> Sucursal\PaymentController@store).
        // El prefijo /sucursal ("sucursal.workbench.payment", mismo controlador) exige
        // role:admin-sucursal|superadmin y devolvería 403 para un cajero.
        return route('caja.payment.store', [$this->tenant->slug, $sale->id]);
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

    public function test_service_attaches_file_to_private_disk(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        [, $payment] = $this->makeSaleWithTransferPayment();

        $created = app(PaymentReceiptService::class)->attach(
            $payment,
            [UploadedFile::fake()->image('captura.jpg', 400, 400)],
            $this->cajero->id,
        );

        $this->assertCount(1, $created);
        $receipt = $created[0];
        $this->assertSame($payment->id, $receipt->payment_id);
        $this->assertNull($receipt->customer_payment_id);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/payment_receipts/p-{$payment->id}/", $receipt->path);
        Storage::disk(PaymentReceiptService::disk())->assertExists($receipt->path);
    }

    public function test_service_attaches_to_customer_payment_and_delete_removes_file(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => Customer::create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branch->id,
                'name' => 'Cliente F',
                'status' => 'active',
            ])->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-TEST1',
            'method' => 'transfer',
            'amount_received' => 200,
            'amount_applied' => 200,
            'change_given' => 0,
            'sales_affected_count' => 0,
        ]);

        $svc = app(PaymentReceiptService::class);
        $created = $svc->attach($cg, [UploadedFile::fake()->create('comp.pdf', 100, 'application/pdf')], $this->cajero->id);

        $this->assertSame($cg->id, $created[0]->customer_payment_id);

        $path = $created[0]->path;
        $svc->delete($created[0]);
        Storage::disk(PaymentReceiptService::disk())->assertMissing($path);
        $this->assertSame(0, PaymentReceipt::count());
    }

    public function test_paying_by_transfer_with_receipt_stores_file(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $sale = $this->makeActiveSale();
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)
            ->post($this->payUrl($sale), [
                'method' => 'transfer',
                'amount' => 100,
                'receipts' => [UploadedFile::fake()->image('captura.jpg')],
            ])->assertSessionHas('success');

        $payment = $sale->payments()->first();
        $this->assertSame(1, $payment->receipts()->count());
    }

    public function test_required_blocks_transfer_without_receipt(): void
    {
        $this->branch->forceFill(['payment_receipts_required' => true])->save();
        $sale = $this->makeActiveSale();
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)
            ->post($this->payUrl($sale), ['method' => 'transfer', 'amount' => 100])
            ->assertSessionHasErrors(['receipts' => 'Adjunta el comprobante de la transferencia.']);

        $this->assertSame(0, $sale->payments()->count());
    }

    public function test_required_does_not_affect_cash(): void
    {
        $this->branch->forceFill(['payment_receipts_required' => true])->save();
        $sale = $this->makeActiveSale();
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)
            ->post($this->payUrl($sale), ['method' => 'cash', 'amount' => 100])
            ->assertSessionHas('success');
    }
}
