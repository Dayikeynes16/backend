<?php

namespace Tests\Feature\Caja;

use App\Enums\SaleStatus;
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
