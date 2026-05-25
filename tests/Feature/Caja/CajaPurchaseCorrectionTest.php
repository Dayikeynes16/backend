<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaPurchaseCorrectionTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function openShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'opened_at' => now(), 'opening_amount' => 1000,
        ]);
    }

    private function purchase(CashRegisterShift $shift, float $paid = 0, float $total = 100): Purchase
    {
        return Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P', 'type' => 'otro'])->id,
            'folio' => 'CMP-'.uniqid(), 'purchased_at' => now(), 'status' => 'received',
            'subtotal' => $total, 'total' => $total, 'amount_paid' => $paid, 'amount_pending' => $total - $paid,
            'created_by' => $this->cajero->id, 'cash_register_shift_id' => $shift->id,
        ]);
    }

    public function test_cajero_registers_payment_with_open_shift(): void
    {
        $shift = $this->openShift();
        $purchase = $this->purchase($shift, 0, 100);

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['amount' => 100])->assertRedirect();

        $purchase->refresh();
        $this->assertSame('100.00', $purchase->amount_paid);
        $this->assertDatabaseHas('provider_payments', [
            'purchase_id' => $purchase->id, 'cash_register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount' => 100,
        ]);
    }

    public function test_cajero_cannot_pay_purchase_of_a_closed_shift(): void
    {
        $shift = $this->openShift();
        $purchase = $this->purchase($shift, 0, 100);
        $shift->update(['closed_at' => now()]);

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['amount' => 100])->assertForbidden();
    }

    public function test_cajero_cannot_edit_another_cajeros_purchase(): void
    {
        $shift = $this->openShift();
        $otro = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P2', 'type' => 'otro'])->id,
            'folio' => 'CMP-AJENA', 'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'created_by' => $otro->id, 'cash_register_shift_id' => $shift->id,
        ]);

        $this->actingAs($this->cajero);
        $this->put(route('caja.compras.update', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'provider_id' => $purchase->provider_id, 'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 50]],
        ])->assertForbidden();
    }

    public function test_cajero_cancels_own_open_shift_purchase(): void
    {
        $shift = $this->openShift();
        $purchase = $this->purchase($shift, 0, 100);

        $this->actingAs($this->cajero);
        $this->patch(route('caja.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['reason' => 'me equivoqué'])->assertRedirect();

        $this->assertSame('cancelled', $purchase->refresh()->status->value);
    }
}
