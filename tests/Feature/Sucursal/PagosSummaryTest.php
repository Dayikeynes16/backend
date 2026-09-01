<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use Carbon\CarbonInterface;
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

    /**
     * Tres pagos en tres días distintos, para ejercitar el rango.
     *
     * @return array{hoy: Payment, ayer: Payment, hace5: Payment}
     */
    private function seedTresDias(): array
    {
        $mk = function (string $method, float $amount, CarbonInterface $when): Payment {
            $sale = $this->makeSale(['total' => $amount, 'created_at' => $when]);

            return $this->makePayment([
                'sale_id' => $sale->id,
                'user_id' => $this->cajero->id,
                'method' => $method,
                'amount' => $amount,
                'created_at' => $when,
            ]);
        };

        return [
            'hoy' => $mk('cash', 100, now()),
            'ayer' => $mk('card', 200, now()->subDay()->setTime(12, 0)),
            'hace5' => $mk('transfer', 400, now()->subDays(5)->setTime(12, 0)),
        ];
    }

    private function pagosProps(string $query): array
    {
        $this->actingAs($this->adminSucursal);

        return $this->get(route('sucursal.pagos.index', $this->tenant->slug).$query)
            ->viewData('page')['props'];
    }

    public function test_un_rango_a_medida_suma_solo_los_dias_incluidos(): void
    {
        $this->seedTresDias();

        $props = $this->pagosProps(
            '?from='.now()->subDay()->toDateString().'&to='.now()->toDateString()
        );

        // Hoy (100) + ayer (200). El de hace 5 días queda fuera.
        $this->assertSame(300.0, $props['periodSummary']['total_collected']);
        $this->assertSame(2, $props['periodSummary']['payment_count']);
        $this->assertCount(2, $props['payments']['data']);
    }

    public function test_el_preset_de_ayer_deja_fuera_lo_de_hoy(): void
    {
        $this->seedTresDias();

        $props = $this->pagosProps('?preset=yesterday');

        $this->assertSame(200.0, $props['periodSummary']['total_collected']);
        $this->assertSame('yesterday', $props['range']['preset']);
    }

    public function test_sin_parametros_el_rango_es_hoy(): void
    {
        $this->seedTresDias();

        $props = $this->pagosProps('');

        // Mismo comportamiento que antes del rango: por defecto, el día en curso.
        $this->assertSame(100.0, $props['periodSummary']['total_collected']);
        $this->assertSame('today', $props['range']['preset']);
    }

    public function test_el_parametro_date_sigue_funcionando_como_rango_de_un_dia(): void
    {
        // Hay enlaces guardados y accesos directos que llevan `?date=`.
        $this->seedTresDias();

        $props = $this->pagosProps('?date='.now()->subDay()->toDateString());

        $this->assertSame(200.0, $props['periodSummary']['total_collected']);
    }

    public function test_el_rango_convive_con_el_filtro_de_metodo(): void
    {
        $this->seedTresDias();

        $props = $this->pagosProps(
            '?preset=last_7_days&method=transfer'
        );

        // Los tres pagos caen en la ventana, pero solo uno es transferencia.
        $this->assertCount(1, $props['payments']['data']);
        $this->assertSame('transfer', $props['payments']['data'][0]['method']);
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
        $summary = $response->viewData('page')['props']['periodSummary'];

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
        $summary = $response->viewData('page')['props']['periodSummary'];

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
        $summary = $response->viewData('page')['props']['periodSummary'];

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

    public function test_transfer_payment_includes_receipts_in_response(): void
    {
        // T10: espejo de test_transfer_payment_includes_receipts_in_response en
        // tests/Feature/Caja/PagosIndexTest.php — `sucursal.pagos.index` necesita
        // `payments.data.*.receipts` para el clip de comprobantes (T9).
        $sale = $this->makeSale(['created_at' => now()]);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'transfer',
            'amount' => 100,
            'created_at' => now(),
        ]);
        PaymentReceipt::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'uploaded_by' => $this->cajero->id,
            'original_name' => 'comprobante.jpg',
            'path' => 'tenants/x/payment_receipts/p-'.$payment->id.'/a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1234,
        ]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.pagos.index', $this->tenant->slug).'?date='.now()->toDateString())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sucursal/Pagos/Index')
                ->has('payments.data.0.receipts', 1)
                ->where('payments.data.0.receipts.0.original_name', 'comprobante.jpg')
            );
    }

    public function test_daily_summary_groups_correctly_by_active_methods_only(): void
    {
        $sale = $this->makeSale(['created_at' => now()]);

        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'card', 'amount' => 200, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'transfer', 'amount' => 300, 'created_at' => now()]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.pagos.index', $this->tenant->slug).'?date='.now()->toDateString());
        $summary = $response->viewData('page')['props']['periodSummary'];

        $byMethod = collect($summary['by_method'])->keyBy('method');
        $this->assertEquals(100.0, $byMethod['cash']['total']);
        $this->assertEquals(200.0, $byMethod['card']['total']);
        $this->assertEquals(300.0, $byMethod['transfer']['total']);
        $this->assertSame(600.0, $summary['total_collected']);
    }
}
