<?php

namespace Tests\Feature\Caja;

use App\Enums\PurchaseStatus;
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

    private function makePurchase(int $userId, string $folio): Purchase
    {
        return Purchase::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'Prov '.$folio, 'type' => 'mayorista_carne'])->id,
            'folio' => $folio,
            'purchased_at' => now(),
            'status' => PurchaseStatus::Received->value,
            'subtotal' => 100,
            'total' => 100,
            'amount_paid' => 100,
            'amount_pending' => 0,
            'created_by' => $userId,
        ]);
    }

    public function test_index_shows_only_the_cajeros_own_purchases(): void
    {
        $this->makePurchase($this->cajero->id, 'MÍA-1');

        $otroCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $this->makePurchase($otroCajero->id, 'AJENA-1');

        $this->actingAs($this->cajero)
            ->get(route('caja.compras.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Compras/Index')
                ->has('purchases.data', 1)
                ->where('purchases.data.0.folio', 'MÍA-1'));
    }

    public function test_index_forbidden_when_purchases_disabled_for_branch(): void
    {
        $this->branch->update(['cashier_purchases_enabled' => false]);

        $this->actingAs($this->cajero)
            ->get(route('caja.compras.index', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_store_forbidden_when_purchases_disabled_for_branch(): void
    {
        $this->branch->update(['cashier_purchases_enabled' => false]);
        $this->openShift();

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.store', $this->tenant->slug), $this->payload(200))->assertForbidden();
        $this->assertSame(0, Purchase::count());
    }
}
