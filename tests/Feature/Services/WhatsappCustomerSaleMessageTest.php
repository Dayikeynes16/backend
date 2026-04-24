<?php

namespace Tests\Feature\Services;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\WhatsappMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class WhatsappCustomerSaleMessageTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private WhatsappMessageService $svc;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->svc = new WhatsappMessageService();
        Carbon::setTestNow('2026-04-24 20:00:00');

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Pérez',
            'phone' => '+523312345678',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeSale(array $attrs = [], array $items = []): Sale
    {
        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->cajero->id,
            'folio' => 'FV-0042',
            'payment_method' => 'cash',
            'total' => 540,
            'amount_paid' => 540,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed->value,
        ], $attrs));

        foreach ($items as $it) {
            SaleItem::create(array_merge([
                'sale_id' => $sale->id,
                'product_name' => 'Producto',
                'unit_type' => 'kg',
                'quantity' => 1,
                'unit_price' => 100,
                'subtotal' => 100,
            ], $it));
        }

        return $sale->fresh(['items']);
    }

    public function test_paid_sale_greets_and_confirms_purchase(): void
    {
        $sale = $this->makeSale([], [
            ['product_name' => 'Arrachera', 'unit_type' => 'kg', 'quantity' => 2.5, 'unit_price' => 150, 'subtotal' => 375],
            ['product_name' => 'Chorizo', 'unit_type' => 'kg', 'quantity' => 1.5, 'unit_price' => 110, 'subtotal' => 165],
        ]);

        $text = $this->svc->buildCustomerSaleText($sale);

        $this->assertStringContainsString('Hola *Juan Pérez*', $text);
        $this->assertStringContainsString('Confirmamos tu compra *FV-0042*', $text);
        $this->assertStringContainsString('Test — Sucursal 1', $text);
        $this->assertStringContainsString('Total cobrado: $540.00', $text);
        $this->assertStringContainsString('Método: Efectivo', $text);
        $this->assertStringContainsString('2.500 kg × Arrachera — $375.00', $text);
        $this->assertStringContainsString('1.500 kg × Chorizo — $165.00', $text);
        $this->assertStringContainsString('¡Gracias por tu compra!', $text);
        $this->assertStringNotContainsString('Pendiente:', $text);
    }

    public function test_pending_sale_shows_partial_payment_and_balance(): void
    {
        $sale = $this->makeSale([
            'total' => 540,
            'amount_paid' => 200,
            'amount_pending' => 340,
        ], [
            ['product_name' => 'Arrachera', 'unit_type' => 'kg', 'quantity' => 2.5, 'unit_price' => 150, 'subtotal' => 375],
            ['product_name' => 'Chorizo', 'unit_type' => 'kg', 'quantity' => 1.5, 'unit_price' => 110, 'subtotal' => 165],
        ]);

        $text = $this->svc->buildCustomerSaleText($sale);

        $this->assertStringContainsString('Te compartimos el detalle de tu pedido *FV-0042*', $text);
        $this->assertStringContainsString('Total: $540.00', $text);
        $this->assertStringContainsString('Pagado: $200.00', $text);
        $this->assertStringContainsString('Pendiente: $340.00', $text);
        $this->assertStringContainsString('Cualquier duda con tu pedido, responde a este mensaje.', $text);
        $this->assertStringNotContainsString('Total cobrado:', $text);
    }

    public function test_pending_sale_without_partial_payment_omits_paid_line(): void
    {
        $sale = $this->makeSale([
            'total' => 200,
            'amount_paid' => 0,
            'amount_pending' => 200,
        ]);

        $text = $this->svc->buildCustomerSaleText($sale);

        $this->assertStringContainsString('Total: $200.00', $text);
        $this->assertStringContainsString('Pendiente: $200.00', $text);
        $this->assertStringNotContainsString('Pagado:', $text);
    }

    public function test_cancelled_sale_uses_short_apology_message(): void
    {
        $sale = $this->makeSale([
            'status' => SaleStatus::Cancelled->value,
        ]);

        $text = $this->svc->buildCustomerSaleText($sale);

        $this->assertStringContainsString('Hola *Juan Pérez*', $text);
        $this->assertStringContainsString('Tu pedido *FV-0042*', $text);
        $this->assertStringContainsString('fue cancelado', $text);
        $this->assertStringNotContainsString('Productos:', $text);
        $this->assertStringNotContainsString('Total', $text);
    }

    public function test_sale_without_items_still_produces_valid_message(): void
    {
        $sale = $this->makeSale();

        $text = $this->svc->buildCustomerSaleText($sale);

        $this->assertStringContainsString('Confirmamos tu compra', $text);
        $this->assertStringNotContainsString('Productos:', $text);
    }

    public function test_message_stays_under_max_bytes(): void
    {
        $items = [];
        for ($i = 0; $i < 200; $i++) {
            $items[] = ['product_name' => 'Producto largo '.$i, 'unit_type' => 'kg', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100];
        }
        $sale = $this->makeSale([], $items);

        $text = $this->svc->buildCustomerSaleText($sale);

        $this->assertLessThanOrEqual(3500, strlen($text));
    }
}
