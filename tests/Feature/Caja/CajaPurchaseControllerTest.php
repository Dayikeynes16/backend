<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaPurchaseControllerTest extends TestCase
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
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now(),
            'opening_amount' => 1000,
        ]);
    }

    private function payload(float $paid): array
    {
        return [
            'provider_id' => Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne'])->id,
            'purchased_at' => now()->toDateString(),
            'paid_amount' => $paid,
            'items' => [[
                'concept' => 'Media canal de res',
                'quantity' => 2,
                'unit' => 'kg',
                'unit_price' => 100,
            ]],
        ];
    }

    public function test_cajero_registers_cash_purchase_tied_to_shift(): void
    {
        $shift = $this->openShift();

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.store', $this->tenant->slug), $this->payload(200))->assertRedirect();

        $purchase = Purchase::first();
        $this->assertNotNull($purchase);
        $this->assertSame($shift->id, $purchase->cash_register_shift_id);
        $this->assertSame($this->branch->id, $purchase->branch_id);
        $this->assertSame('200.00', $purchase->amount_paid);

        $this->assertDatabaseHas('provider_payments', [
            'purchase_id' => $purchase->id,
            'cash_register_shift_id' => $shift->id,
            'payment_method' => 'cash',
            'amount' => 200,
        ]);
    }

    public function test_partial_cash_payment_leaves_pending(): void
    {
        $this->openShift();

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.store', $this->tenant->slug), $this->payload(50))->assertRedirect();

        $purchase = Purchase::first();
        $this->assertSame('50.00', $purchase->amount_paid);
        $this->assertSame('150.00', $purchase->amount_pending); // total 200
    }

    public function test_requires_open_shift(): void
    {
        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.store', $this->tenant->slug), $this->payload(200))->assertStatus(422);
        $this->assertSame(0, Purchase::count());
    }
}
