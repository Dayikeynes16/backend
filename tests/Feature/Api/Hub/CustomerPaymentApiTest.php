<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\SalePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerPaymentApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Fiado',
            'phone' => '6611112222',
            'status' => 'active',
        ]);
    }

    private function token(string $role = 'cajero'): string
    {
        $user = $role === 'cajero' ? $this->cajero : $this->adminSucursal;

        return $user->createToken('hub')->plainTextToken;
    }

    private function openShift(?int $userId = null): void
    {
        CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            // El cobro global es de admin-sucursal (paridad web), así que el
            // turno abierto por defecto es el del admin.
            'user_id' => $userId ?? $this->adminSucursal->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 0,
        ]);
    }

    private function pendingSale(float $total, $createdAt): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'api',
            'status' => SaleStatus::Active,
            'created_at' => $createdAt,
        ]);
    }

    public function test_cajero_cannot_register_global_payment(): void
    {
        $this->openShift($this->cajero->id);
        $this->pendingSale(100, now()->subDay());

        $this->withToken($this->token('cajero'))
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/payments", [
                'amount_received' => 50,
                'method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_global_payment_distributes_fifo(): void
    {
        $this->openShift();
        $first = $this->pendingSale(100, now()->subDays(2));
        $second = $this->pendingSale(50, now()->subDay());

        $res = $this->withToken($this->token('admin'))
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/payments", [
                'amount_received' => 120,
                'method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('customer_payment.amount_applied', 120)
            ->assertJsonPath('customer_payment.change_given', 0);

        $applied = collect($res->json('applied'))->keyBy('sale_id');
        $this->assertTrue($applied[$first->id]['completed']);          // 100 saldado
        $this->assertEquals(30, $applied[$second->id]['new_pending']); // 50 - 20

        $this->assertSame('completed', $first->refresh()->status->value);
        $this->assertEquals(30, $second->refresh()->amount_pending);
    }

    public function test_cash_overpayment_returns_change(): void
    {
        $this->openShift();
        $this->pendingSale(100, now()->subDay());

        $this->withToken($this->token('admin'))
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/payments", [
                'amount_received' => 150,
                'method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('customer_payment.amount_applied', 100)
            ->assertJsonPath('customer_payment.change_given', 50);
    }

    public function test_non_cash_overpayment_rejected(): void
    {
        $this->openShift();
        $this->pendingSale(100, now()->subDay());

        $this->withToken($this->token('admin'))
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/payments", [
                'amount_received' => 150,
                'method' => 'card',
            ])
            ->assertStatus(422);
    }

    public function test_requires_open_shift(): void
    {
        $this->pendingSale(100, now()->subDay());

        $this->withToken($this->token('admin'))
            ->postJson("/api/v1/hub/customers/{$this->customer->id}/payments", [
                'amount_received' => 50,
                'method' => 'cash',
            ])
            ->assertStatus(409);
    }

    public function test_ledger_lists_pending_and_total(): void
    {
        $this->pendingSale(100, now()->subDay());
        $this->pendingSale(50, now());

        $res = $this->withToken($this->token())
            ->getJson("/api/v1/hub/customers/{$this->customer->id}/payments")
            ->assertOk();

        $this->assertCount(2, $res->json('pending_sales'));
        $this->assertEquals(150, $res->json('total_owed'));
    }

    /**
     * Crea un cobro global ya aplicado a la venta (vía modelos) para probar la
     * cancelación sin encadenar dos peticiones con usuarios distintos (el guard
     * de Sanctum cachea el usuario dentro del mismo test).
     */
    private function appliedGlobalPayment(Sale $sale): CustomerPayment
    {
        $cp = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-00001',
            'method' => 'cash',
            'amount_received' => (float) $sale->total,
            'amount_applied' => (float) $sale->total,
            'change_given' => 0,
            'sales_affected_count' => 1,
        ]);
        Payment::create([
            'sale_id' => $sale->id,
            'customer_payment_id' => $cp->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => (float) $sale->total,
        ]);
        app(SalePaymentService::class)->recalculate($sale, $this->cajero);

        return $cp;
    }

    public function test_admin_cancels_global_payment_and_restores_debt(): void
    {
        $sale = $this->pendingSale(100, now()->subDay());
        $cp = $this->appliedGlobalPayment($sale);
        $this->assertSame('completed', $sale->refresh()->status->value);

        $this->withToken($this->token('admin'))
            ->deleteJson("/api/v1/hub/customers/{$this->customer->id}/payments/{$cp->id}", ['cancel_reason' => 'cobro equivocado'])
            ->assertOk();

        $this->assertEquals(100, $sale->refresh()->amount_pending);
        $this->assertSame('active', $sale->status->value);
    }

    public function test_cajero_cannot_cancel_global_payment(): void
    {
        $sale = $this->pendingSale(100, now()->subDay());
        $cp = $this->appliedGlobalPayment($sale);

        $this->withToken($this->token('cajero'))
            ->deleteJson("/api/v1/hub/customers/{$this->customer->id}/payments/{$cp->id}", ['cancel_reason' => 'error'])
            ->assertStatus(403);
    }
}
