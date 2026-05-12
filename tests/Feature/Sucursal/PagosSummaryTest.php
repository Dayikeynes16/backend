<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PagosSummaryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeSale(array $attrs = []): Sale
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 100,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
        ], $attrs));

        if ($createdAt) {
            $sale->forceFill(['created_at' => $createdAt])->save();
        }

        return $sale;
    }

    private function makePayment(array $attrs): Payment
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $payment = Payment::create($attrs);

        if ($createdAt) {
            $payment->forceFill(['created_at' => $createdAt])->save();
        }

        return $payment;
    }

    public function test_daily_summary_includes_payments_made_today_for_old_sales(): void
    {
        // Venta de hace 3 días pagada hoy → DEBE aparecer en pagos de hoy
        $oldSale = $this->makeSale([
            'total' => 800,
            'created_at' => now()->subDays(3),
        ]);
        Payment::create([
            'sale_id' => $oldSale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 800,
            'created_at' => now(),
        ]);

        // Venta de hoy pagada hoy
        $todaySale = $this->makeSale(['total' => 200, 'created_at' => now()]);
        Payment::create([
            'sale_id' => $todaySale->id,
            'user_id' => $this->cajero->id,
            'method' => 'card',
            'amount' => 200,
            'created_at' => now(),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.pagos.index', $this->tenant->slug).'?date='.now()->toDateString());
        $summary = $response->viewData('page')['props']['dailySummary'];

        // Total cobrado hoy = 800 (de venta vieja) + 200 (de venta de hoy) = 1000
        $this->assertSame(1000.0, $summary['total_collected']);
        $this->assertSame(2, $summary['payment_count']);

        $byMethod = collect($summary['by_method'])->keyBy('method');
        $this->assertEquals(800.0, $byMethod['cash']['total']);
        $this->assertEquals(200.0, $byMethod['card']['total']);
    }

    public function test_daily_summary_excludes_payments_made_other_days(): void
    {
        $sale = $this->makeSale(['created_at' => now()]);

        // Pago de ayer → NO entra al resumen de hoy
        $this->makePayment([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 50,
            'created_at' => now()->subDay(),
        ]);
        // Pago de hoy → SÍ entra (no necesita helper, default = now)
        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 50,
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.pagos.index', $this->tenant->slug).'?date='.now()->toDateString());
        $summary = $response->viewData('page')['props']['dailySummary'];

        $this->assertSame(50.0, $summary['total_collected']);
        $this->assertSame(1, $summary['payment_count']);
    }

    public function test_daily_summary_excludes_soft_deleted_payments(): void
    {
        $sale = $this->makeSale(['created_at' => now()]);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 500,
            'created_at' => now(),
        ]);
        $payment->delete();

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.pagos.index', $this->tenant->slug).'?date='.now()->toDateString());
        $summary = $response->viewData('page')['props']['dailySummary'];

        $this->assertSame(0.0, $summary['total_collected']);
        $this->assertSame(0, $summary['payment_count']);
    }

    public function test_pagos_list_can_be_filtered_by_customer_presence(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Uno',
            'phone' => '5551234567',
        ]);

        $saleWithCustomer = $this->makeSale(['created_at' => now(), 'customer_id' => $customer->id]);
        $saleWalkIn = $this->makeSale(['created_at' => now()]);

        $paymentWith = Payment::create(['sale_id' => $saleWithCustomer->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100, 'created_at' => now()]);
        $paymentWalkIn = Payment::create(['sale_id' => $saleWalkIn->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 50, 'created_at' => now()]);

        $this->actingAs($this->adminSucursal);
        $date = now()->toDateString();
        $base = route('sucursal.pagos.index', $this->tenant->slug);

        $all = $this->get("{$base}?date={$date}")->viewData('page')['props']['payments']['data'];
        $this->assertEqualsCanonicalizing([$paymentWith->id, $paymentWalkIn->id], collect($all)->pluck('id')->all());

        $withCustomer = $this->get("{$base}?date={$date}&customer=with")->viewData('page')['props']['payments']['data'];
        $this->assertSame([$paymentWith->id], collect($withCustomer)->pluck('id')->all());
        $this->assertSame('Cliente Uno', $withCustomer[0]['sale']['customer']['name']);

        $withoutCustomer = $this->get("{$base}?date={$date}&customer=without")->viewData('page')['props']['payments']['data'];
        $this->assertSame([$paymentWalkIn->id], collect($withoutCustomer)->pluck('id')->all());
        $this->assertNull($withoutCustomer[0]['sale']['customer']);
    }

    public function test_daily_summary_groups_correctly_by_active_methods_only(): void
    {
        $sale = $this->makeSale(['created_at' => now()]);

        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'card', 'amount' => 200, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'transfer', 'amount' => 300, 'created_at' => now()]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.pagos.index', $this->tenant->slug).'?date='.now()->toDateString());
        $summary = $response->viewData('page')['props']['dailySummary'];

        $byMethod = collect($summary['by_method'])->keyBy('method');
        $this->assertEquals(100.0, $byMethod['cash']['total']);
        $this->assertEquals(200.0, $byMethod['card']['total']);
        $this->assertEquals(300.0, $byMethod['transfer']['total']);
        $this->assertSame(600.0, $summary['total_collected']);
    }
}
