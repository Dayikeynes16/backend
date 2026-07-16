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
use Illuminate\Support\Facades\Route;
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

    /**
     * CG creado directamente por modelo (no por endpoint): el cajero no tiene
     * ruta para crear cobros globales (eso es exclusivo de admin-sucursal en
     * la web; el cajero solo los crea vía el asistente IA). Espejo del
     * CustomerPayment::create inline usado en PaymentReceiptTest.
     */
    private function makeCustomerPayment(User $owner, array $overrides = []): CustomerPayment
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente F',
            'status' => 'active',
        ]);

        // created_at no está en el #[Fillable] del modelo: CustomerPayment::create()
        // lo ignora silenciosamente y sella now(). Para controlar el timestamp hay
        // que aplicarlo con forceFill() después de crear (espejo del patrón usado
        // en tests/Feature/Sucursal/PaymentReceiptTest.php con Payment).
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $cg = CustomerPayment::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'folio' => 'CG-'.fake()->unique()->numerify('#####'),
            'method' => 'transfer',
            'amount_received' => 200,
            'amount_applied' => 200,
            'change_given' => 0,
            'sales_affected_count' => 0,
        ], $overrides));

        if ($createdAt !== null) {
            $cg->forceFill(['created_at' => $createdAt])->save();
        }

        return $cg;
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

    // --- Endpoints de comprobantes sobre un CG ya existente (T6) ---
    // Espejo de tests/Feature/Sucursal/PaymentReceiptTest.php, adaptado a
    // CustomerPayment (que tiene branch_id/user_id propios, sin sale).

    public function test_admin_attaches_receipt_later_via_sucursal_route(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = $this->makeCustomerPayment($this->adminSucursal);

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('tarde.jpg')]],
        )->assertSessionHas('success');

        $this->assertSame(1, $cg->receipts()->count());
    }

    // NOTA: el actor cajero solo puede usar el prefijo /caja
    // ("caja.cobros.receipts.*" -> mismo Sucursal\CustomerPaymentReceiptController).
    // El prefijo /sucursal exige role:admin-sucursal|superadmin y devolvería
    // 403 por el gate de rol del grupo, no por la regla de turno. El CG se
    // crea por modelo (no hay ruta de creación para cajero): user_id del
    // cajero y created_at >= opened_at de su turno.
    public function test_owner_cajero_attaches_and_downloads_receipt_via_caja_route_within_shift(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $shift = $this->openShiftFor($this->cajero);
        $cg = $this->makeCustomerPayment($this->cajero, ['created_at' => $shift->opened_at->copy()->addMinute()]);

        $this->actingAs($this->cajero)->post(
            route('caja.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('tarde.jpg')]],
        )->assertSessionHas('success');

        $receipt = $cg->receipts()->firstOrFail();

        $this->actingAs($this->cajero)->get(
            route('caja.cobros.receipts.download', [$this->tenant->slug, $cg->id, $receipt->id]),
        )->assertOk()->assertDownload('tarde.jpg');
    }

    // Reglas de turno del cajero sobre comprobantes de CG (authorizeMutation
    // en CustomerPaymentReceiptController): solo puede mutar comprobantes de
    // SUS PROPIOS cobros globales y solo si el CG fue creado dentro de su
    // turno abierto. Espejo de los tests homónimos de PaymentReceiptTest.
    public function test_cajero_cannot_mutate_cg_without_open_shift(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = $this->makeCustomerPayment($this->cajero);
        // Sin turno abierto → 403.
        $this->actingAs($this->cajero)->post(
            route('caja.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertStatus(403);
    }

    public function test_cajero_cannot_mutate_another_users_cg(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $cg = $this->makeCustomerPayment($otherCajero);
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)->post(
            route('caja.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertStatus(403);
    }

    public function test_cajero_cannot_mutate_cg_created_before_his_shift(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = $this->makeCustomerPayment($this->cajero, ['created_at' => now()->subDay()]);
        $this->openShiftFor($this->cajero); // opened_at = now(), después del CG

        $this->actingAs($this->cajero)->post(
            route('caja.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertStatus(403);
    }

    public function test_flag_off_returns_403_with_exact_message(): void
    {
        $this->branch->forceFill(['payment_receipts_enabled' => false, 'payment_receipts_required' => false])->save();
        $cg = $this->makeCustomerPayment($this->adminSucursal);

        $this->actingAs($this->adminSucursal)->postJson(
            route('sucursal.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertStatus(403)->assertJsonPath('message', 'Tu empresa no ha habilitado esta función para tu sucursal.');
    }

    public function test_cash_method_rejects_receipt(): void
    {
        $cg = $this->makeCustomerPayment($this->adminSucursal, ['method' => 'cash']);

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertSessionHasErrors(['receipts' => 'Solo los pagos por transferencia llevan comprobante.']);
    }

    public function test_destroy_only_allowed_via_sucursal_route_admin(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = $this->makeCustomerPayment($this->adminSucursal);
        $receipt = app(PaymentReceiptService::class)->attach($cg, [UploadedFile::fake()->image('c.jpg')], $this->adminSucursal->id)[0];

        // El cajero no tiene ruta destroy sobre comprobantes de CG (paridad
        // limitada a adjuntar/descargar — decisión del coordinador).
        $this->assertFalse(Route::has('caja.cobros.receipts.destroy'));

        $this->actingAs($this->adminSucursal)->delete(
            route('sucursal.cobros.receipts.destroy', [$this->tenant->slug, $cg->id, $receipt->id]),
        )->assertSessionHas('success');

        $this->assertSame(0, $cg->receipts()->count());
    }

    public function test_receipts_cap_at_three_accumulated(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = $this->makeCustomerPayment($this->adminSucursal);
        app(PaymentReceiptService::class)->attach($cg, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ], $this->adminSucursal->id);

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('c.jpg'), UploadedFile::fake()->image('d.jpg')]],
        )->assertSessionHasErrors(['receipts' => 'Máximo 3 comprobantes por pago.']);

        $this->assertSame(2, $cg->receipts()->count());
    }

    public function test_receipt_belonging_to_another_customer_payment_returns_404(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg1 = $this->makeCustomerPayment($this->adminSucursal);
        $cg2 = $this->makeCustomerPayment($this->adminSucursal);
        $receiptOfCg1 = app(PaymentReceiptService::class)->attach($cg1, [UploadedFile::fake()->image('a.jpg')], $this->adminSucursal->id)[0];

        $this->actingAs($this->adminSucursal)->get(
            route('sucursal.cobros.receipts.download', [$this->tenant->slug, $cg2->id, $receiptOfCg1->id]),
        )->assertNotFound();
    }

    public function test_cancelling_global_payment_preserves_receipts(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $customer = $this->makeCustomerWithDebt(200);
        $this->openShiftFor($this->adminSucursal);

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.clientes.cobro-global', [$this->tenant->slug, $customer->id]),
            ['amount_received' => 200, 'method' => 'transfer', 'receipts' => [UploadedFile::fake()->image('cap.jpg')]],
        )->assertCreated();

        $cg = CustomerPayment::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(1, $cg->receipts()->count());

        // Cancelar es un soft-delete (CustomerPaymentController@destroy marca
        // cancelled_* y hace $customerPayment->delete() con SoftDeletes) — no
        // borra físicamente el CG ni sus comprobantes: quedan como evidencia.
        $this->actingAs($this->adminSucursal)->delete(
            route('sucursal.clientes.cobro-global.cancel', [$this->tenant->slug, $customer->id, $cg->id]),
            ['cancel_reason' => 'Error de captura'],
        )->assertOk();

        $this->assertNotNull($cg->fresh()->cancelled_at);
        $this->assertSoftDeleted($cg);
        $this->assertSame(1, PaymentReceipt::where('customer_payment_id', $cg->id)->count());
    }
}
