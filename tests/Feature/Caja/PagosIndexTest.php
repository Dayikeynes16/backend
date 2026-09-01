<?php

namespace Tests\Feature\Caja;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PagosIndexTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function makePayment(array $attrs): Payment
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $attrs['amount'] ?? 100,
            'amount_paid' => $attrs['amount'] ?? 100,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed->value,
            'completed_at' => now(),
        ]);

        return Payment::create(array_merge([
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => 100,
        ], $attrs));
    }

    public function test_el_rango_filtra_los_pagos_del_cajero(): void
    {
        $hoy = $this->makePayment(['user_id' => $this->cajero->id, 'amount' => 100, 'method' => 'cash']);
        $viejo = $this->makePayment(['user_id' => $this->cajero->id, 'amount' => 300, 'method' => 'card']);
        $viejo->forceFill(['created_at' => now()->subDays(4)->setTime(12, 0)])->save();

        // Por defecto, hoy: el de hace cuatro días queda fuera.
        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('range.preset', 'today'));

        // Con la ventana de siete días entran los dos.
        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug).'?preset=last_7_days')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('payments.data', 2));

        // Y un rango a medida que solo cubre el día del pago viejo.
        $dia = now()->subDays(4)->toDateString();
        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug)."?from={$dia}&to={$dia}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $viejo->id));

        $this->assertNotNull($hoy->id);
    }

    public function test_el_parametro_date_sigue_funcionando_en_caja(): void
    {
        $viejo = $this->makePayment(['user_id' => $this->cajero->id, 'amount' => 300, 'method' => 'card']);
        $viejo->forceFill(['created_at' => now()->subDays(2)->setTime(12, 0)])->save();

        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug).'?date='.now()->subDays(2)->toDateString())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));
    }

    public function test_endpoint_responds_ok_and_uses_qualified_user_id_in_totals_join(): void
    {
        // Regresión: el endpoint hacía un JOIN a `sales` para los totales y el
        // where('user_id', ...) sin calificar tronaba con "column reference
        // 'user_id' is ambiguous" en Postgres (sales también tiene user_id).
        $this->makePayment(['user_id' => $this->cajero->id, 'amount' => 100, 'method' => 'cash']);
        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $this->makePayment(['user_id' => $otherCajero->id, 'amount' => 200, 'method' => 'card']);

        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Pagos/Index')
                // Solo cuenta el pago propio.
                ->has('payments.data', 1)
                ->where('totals.total', '100.00')
            );
    }

    public function test_a_global_collection_exposes_the_sales_it_paid(): void
    {
        // Sin esto el panel del cobro global se corta en su folio y nunca dice a
        // dónde se fue el dinero: es la única forma de saltar a esas ventas.
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Ana Ramírez',
            'status' => 'active',
        ]);

        $cg = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-00042',
            'method' => 'cash',
            'amount_received' => 300,
            'amount_applied' => 300,
            'change_given' => 0,
            'sales_affected_count' => 2,
        ]);

        // Dos pagos hijos del mismo cobro: en la lista se colapsan a un renglón,
        // y el panel debe poder desplegar las dos ventas detrás.
        $first = $this->makePayment([
            'user_id' => $this->cajero->id,
            'amount' => 100,
            'method' => 'cash',
            'customer_payment_id' => $cg->id,
        ]);
        $this->makePayment([
            'user_id' => $this->cajero->id,
            'amount' => 200,
            'method' => 'cash',
            'customer_payment_id' => $cg->id,
        ]);

        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Pagos/Index')
                // Un solo renglón para el cobro, con sus dos ventas dentro.
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $first->id)
                ->has('payments.data.0.customer_payment.payments', 2)
                ->has('payments.data.0.customer_payment.payments.0.sale.folio')
            );
    }

    public function test_transfer_payment_includes_receipts_in_response(): void
    {
        // T9: la lista de Pagos necesita `payments.data.*.receipts` para
        // mostrar el clip de comprobantes junto a los pagos por transferencia.
        $payment = $this->makePayment(['user_id' => $this->cajero->id, 'amount' => 150, 'method' => 'transfer']);
        PaymentReceipt::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'uploaded_by' => $this->cajero->id,
            'original_name' => 'comprobante.jpg',
            'path' => 'tenants/x/payment_receipts/p-'.$payment->id.'/a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1234,
        ]);

        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Caja/Pagos/Index')
                ->has('payments.data.0.receipts', 1)
                ->where('payments.data.0.receipts.0.original_name', 'comprobante.jpg')
            );
    }
}
