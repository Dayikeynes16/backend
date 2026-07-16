<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use App\Models\User;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerPaymentReceiptTest extends TestCase
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

    /**
     * Cliente con una venta a crédito con saldo pendiente (helper adaptado de
     * tests/Feature/Api/Hub/CustomerPaymentApiTest.php::pendingSale()).
     */
    private function makeCustomerWithDebt(float $amount): Customer
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Fiado',
            'status' => 'active',
        ]);

        Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $amount,
            'amount_paid' => 0,
            'amount_pending' => $amount,
            'origin' => 'api',
            'status' => SaleStatus::Active,
            'created_at' => now()->subDay(),
        ]);

        return $customer;
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

    public function test_global_collection_by_transfer_stores_receipt_on_parent(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $customer = $this->makeCustomerWithDebt(200);
        // El cobro global es exclusivo de admin-sucursal (paridad con el hub:
        // ver test_cajero_cannot_register_global_payment en CustomerPaymentApiTest,
        // y la ruta web sucursal.clientes.cobro-global exige role:admin-sucursal|superadmin).
        $this->openShiftFor($this->adminSucursal);

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.clientes.cobro-global', [$this->tenant->slug, $customer->id]),
            ['amount_received' => 200, 'method' => 'transfer', 'receipts' => [UploadedFile::fake()->image('cap.jpg')]],
        )->assertCreated();

        $cg = CustomerPayment::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(1, $cg->receipts()->count());
        // Los pagos hijos NO llevan comprobante propio.
        $this->assertSame(0, PaymentReceipt::whereNotNull('payment_id')->count());
    }

    public function test_required_blocks_global_transfer_without_receipt(): void
    {
        $this->branch->forceFill(['payment_receipts_required' => true])->save();
        $customer = $this->makeCustomerWithDebt(200);
        $this->openShiftFor($this->adminSucursal);

        $this->actingAs($this->adminSucursal)->postJson(
            route('sucursal.clientes.cobro-global', [$this->tenant->slug, $customer->id]),
            ['amount_received' => 200, 'method' => 'transfer'],
        )->assertStatus(422)->assertJsonValidationErrors('receipts');
    }

    public function test_required_does_not_affect_cash_global_payment(): void
    {
        $this->branch->forceFill(['payment_receipts_required' => true])->save();
        $customer = $this->makeCustomerWithDebt(200);
        $this->openShiftFor($this->adminSucursal);

        $this->actingAs($this->adminSucursal)->postJson(
            route('sucursal.clientes.cobro-global', [$this->tenant->slug, $customer->id]),
            ['amount_received' => 200, 'method' => 'cash'],
        )->assertCreated();
    }
}
