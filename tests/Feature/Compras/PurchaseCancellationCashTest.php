<?php

namespace Tests\Feature\Compras;

use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseCancellationCashTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_cancelling_purchase_cancels_cash_payments_and_recalcs_closed_shift(): void
    {
        // Turno cerrado con un pago a proveedor en efectivo de 300.
        $shift = CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'opened_at' => now()->subHours(2), 'closed_at' => now()->subHour(), 'opening_amount' => 1000,
            'expected_amount' => 700, 'total_cash_provider_payments' => 300, 'declared_amount' => 700, 'difference' => 0,
        ]);

        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P', 'type' => 'otro'])->id,
            'folio' => 'CMP-X', 'purchased_at' => now()->subHour(), 'status' => 'received',
            'subtotal' => 300, 'total' => 300, 'amount_paid' => 300, 'amount_pending' => 0,
            'created_by' => $this->cajero->id, 'cash_register_shift_id' => $shift->id,
        ]);
        ProviderPayment::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id, 'provider_id' => $purchase->provider_id,
            'purchase_id' => $purchase->id, 'paid_at' => now()->subHour(), 'amount' => 300,
            'payment_method' => 'cash', 'user_id' => $this->cajero->id,
        ]);

        $this->actingAs($this->adminSucursal);
        $this->patch(route('sucursal.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['reason' => 'duplicada'])->assertRedirect();

        // El pago quedó cancelado.
        $this->assertNotNull(ProviderPayment::where('purchase_id', $purchase->id)->first()->cancelled_at);
        // El corte cerrado se recalculó: ya no resta los 300 → esperado 1000.
        $shift->refresh();
        $this->assertSame('1000.00', $shift->expected_amount);
        $this->assertSame('0.00', $shift->total_cash_provider_payments);
    }
}
