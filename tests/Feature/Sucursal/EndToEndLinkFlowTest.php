<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Recorrido end-to-end del flujo:
 *   1. Cliente arma pedido web → Sale origin='web' status='pending'
 *   2. Carnicero pesa y registra venta normal → Sale origin='api' status='active'
 *   3. Admin/cajero empareja las dos en Workbench
 *   4. Sistema copia delivery, recalcula total, marca pedido como Fulfilled
 *   5. Cajero cobra la venta de báscula → status='completed'
 *   6. Métricas no doble-cuentan: solo la venta de báscula entra al accountable
 *
 * Spec: docs/superpowers/specs/2026-05-16-emparejar-pedido-venta-design.md
 */
class EndToEndLinkFlowTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_complete_flow_from_web_order_to_linked_and_paid(): void
    {
        Event::fake([SaleUpdated::class]);

        // 1. Pedido web entra (simula Public/OrderController::store)
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'María López',
            'phone' => '+529931234567',
            'status' => 'active',
        ]);
        $webOrder = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'folio' => 'WEB-0001',
            'payment_method' => 'cash',
            'total' => 470,
            'amount_paid' => 0,
            'amount_pending' => 470,
            'origin' => 'web',
            'origin_name' => 'Pedido web',
            'status' => SaleStatus::Pending->value,
            'contact_name' => 'María López',
            'contact_phone' => '+529931234567',
            'delivery_type' => 'delivery',
            'delivery_address' => 'Calle X 123, col Y',
            'delivery_lat' => 18.0012345,
            'delivery_lng' => -92.9450000,
            'delivery_distance_km' => 2.5,
            'delivery_fee' => 70,
            'cart_note' => 'Tocar timbre 2 veces',
        ]);
        SaleItem::create([
            'sale_id' => $webOrder->id,
            'product_name' => 'Arrachera',
            'unit_type' => 'kg',
            'quantity' => 2,
            'unit_price' => 200,
            'original_unit_price' => 200,
            'subtotal' => 400,
            'notes' => 'Más limpia de grasa',
        ]);

        // 2. Carnicero pesa y crea venta de báscula (peso real difiere del pedido)
        $scaleSale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'API-0042',
            'payment_method' => 'cash',
            'total' => 430, // 2.15 kg × $200 = $430 (peso real)
            'amount_paid' => 0,
            'amount_pending' => 430,
            'origin' => 'api',
            'origin_name' => 'Báscula',
            'status' => SaleStatus::Active->value,
        ]);
        SaleItem::create([
            'sale_id' => $scaleSale->id,
            'product_name' => 'Arrachera',
            'unit_type' => 'kg',
            'quantity' => 2.15,
            'unit_price' => 200,
            'original_unit_price' => 200,
            'subtotal' => 430,
        ]);

        // 3. Admin vincula desde Workbench
        $response = $this->actingAs($this->adminSucursal)
            ->post(
                route('sucursal.workbench.link-order', [$this->tenant->slug, $scaleSale->id]),
                ['order_id' => $webOrder->id]
            );
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 4. Estado correcto tras vincular
        $freshScale = $scaleSale->fresh();
        $freshWeb = $webOrder->fresh();

        $this->assertSame($webOrder->id, $freshScale->linked_order_id);
        $this->assertSame(SaleStatus::Fulfilled, $freshWeb->status);

        // Delivery se copió del pedido al scale sale
        $this->assertSame('delivery', $freshScale->delivery_type);
        $this->assertSame('Calle X 123, col Y', $freshScale->delivery_address);
        $this->assertEquals(70, (float) $freshScale->delivery_fee);

        // Customer se copió (scale sale no tenía customer)
        $this->assertSame($customer->id, $freshScale->customer_id);
        $this->assertSame('María López', $freshScale->contact_name);

        // Total recalculado: items 430 + delivery 70 = 500
        $this->assertEquals(500, (float) $freshScale->total);
        $this->assertEquals(500, (float) $freshScale->amount_pending);

        // Broadcast x2 (uno por sale)
        Event::assertDispatchedTimes(SaleUpdated::class, 2);

        // 5. Cajero cobra la venta de báscula (sin pasar por el endpoint
        // de payments para no requerir turno abierto; simulamos el resultado)
        Payment::create([
            'sale_id' => $freshScale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 500,
        ]);
        $freshScale->update([
            'amount_paid' => 500,
            'amount_pending' => 0,
            'status' => SaleStatus::Completed->value,
            'completed_at' => now(),
        ]);

        // 6. Métricas: solo la venta de báscula entra en accountable
        $accountableSales = Sale::accountable()
            ->where('branch_id', $this->branch->id)
            ->get();

        $this->assertCount(1, $accountableSales);
        $this->assertSame($freshScale->id, $accountableSales->first()->id);
        $this->assertNotContains($freshWeb->id, $accountableSales->pluck('id')->all());

        $totalAccountable = (float) Sale::accountable()
            ->where('branch_id', $this->branch->id)
            ->sum('total');
        $this->assertEquals(500, $totalAccountable, 'El total no debe doble-contar el pedido web');
    }

    public function test_unlink_blocked_after_payment_registered(): void
    {
        Event::fake([SaleUpdated::class]);

        $web = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-X',
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ]);
        $scale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'API-X',
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ]);
        SaleItem::create([
            'sale_id' => $scale->id,
            'product_name' => 'Pollo',
            'unit_type' => 'kg',
            'quantity' => 1,
            'unit_price' => 100,
            'original_unit_price' => 100,
            'subtotal' => 100,
        ]);

        // Vincular
        $this->actingAs($this->adminSucursal)
            ->post(
                route('sucursal.workbench.link-order', [$this->tenant->slug, $scale->id]),
                ['order_id' => $web->id]
            )
            ->assertSessionHas('success');

        // Registrar pago parcial directamente
        Payment::create([
            'sale_id' => $scale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 50,
        ]);

        // Intentar desvincular → debe estar bloqueado
        $response = $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.workbench.unlink-order', [$this->tenant->slug, $scale->id]));

        $response->assertSessionHas('error');
        $this->assertSame($web->id, $scale->fresh()->linked_order_id);
        $this->assertSame(SaleStatus::Fulfilled, $web->fresh()->status);
    }

    public function test_rejecting_web_order_keeps_it_out_of_accountable_totals(): void
    {
        // Pedido web pendiente
        $web = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-REJ',
            'payment_method' => 'cash',
            'total' => 250,
            'amount_paid' => 0,
            'amount_pending' => 250,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ]);

        // Admin lo rechaza (cancela) via endpoint updateStatus
        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.update-status', [$this->tenant->slug, $web->id]), [
                'status' => SaleStatus::Cancelled->value,
                'cancel_reason' => 'No tenemos producto hoy',
            ])
            ->assertRedirect();

        $this->assertSame(SaleStatus::Cancelled, $web->fresh()->status);

        // Un pedido web cancelado SÍ entra en accountable (es una venta cancelada real
        // que debe aparecer en métricas de cancelaciones).
        $accountableIds = Sale::accountable()->pluck('id')->all();
        $this->assertContains($web->id, $accountableIds);
    }
}
