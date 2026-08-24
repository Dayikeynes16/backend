<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\AuditLogger;
use App\Services\SaleItemEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Facade;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Movimientos: que cada forma de tocar una venta ya cobrada deje su rastro.
 *
 * El `amount_effect` en negativo significa «reduce lo que hay que entregar»: es
 * el número que la pantalla ordena y suma, así que su signo es la prueba que
 * más importa de todas.
 *
 * Los cambios de producto se prueban contra `SaleItemEditor` y no por HTTP
 * porque es el único punto por el que pasan la web y el hub: cubre las dos
 * superficies sin montar dos veces el mismo escenario.
 */
class SaleMovementWritesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function activeSale(float $total = 200): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);
    }

    private function lastLog(AuditEvent $event): AuditLog
    {
        $log = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', (new Sale)->getMorphClass())
            ->where('event', $event->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, "No se registró el evento {$event->value}.");

        return $log;
    }

    public function test_agregar_un_producto_suma_su_subtotal(): void
    {
        $sale = $this->activeSale();
        $product = $this->makeProduct(['price' => 50]);

        app(SaleItemEditor::class)->add($sale, [
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 50,
        ], 'Faltaba un producto', $this->adminSucursal);

        $log = $this->lastLog(AuditEvent::ItemAdded);
        $this->assertSame(100.0, (float) $log->amount_effect);
        $this->assertSame($sale->id, $log->auditable_id);
        $this->assertSame($this->branch->id, $log->branch_id);
        $this->assertSame($this->adminSucursal->id, $log->user_id);
        $this->assertSame($product->name, $log->changes['product']);
    }

    public function test_bajar_el_precio_de_un_producto_deja_efecto_negativo(): void
    {
        $sale = $this->activeSale();
        $product = $this->makeProduct(['price' => 100]);
        $editor = app(SaleItemEditor::class);

        $item = $editor->add($sale, [
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ], null, $this->adminSucursal);

        $editor->update($sale->fresh(), $item, [
            'quantity' => 1,
            'unit_price' => 60,
        ], 'Ajuste', $this->adminSucursal);

        // 60 − 100: la diferencia es exactamente lo que dejó de entrar.
        $log = $this->lastLog(AuditEvent::ItemUpdated);
        $this->assertSame(-40.0, (float) $log->amount_effect);
        $this->assertArrayHasKey('diff', $log->changes);
    }

    public function test_quitar_un_producto_resta_su_subtotal(): void
    {
        $sale = $this->activeSale();
        $product = $this->makeProduct(['price' => 30]);
        $editor = app(SaleItemEditor::class);

        $item = $editor->add($sale, [
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 30,
        ], null, $this->adminSucursal);

        $editor->remove($sale->fresh(), $item, 'Se devolvió', $this->adminSucursal);

        $log = $this->lastLog(AuditEvent::ItemRemoved);
        $this->assertSame(-90.0, (float) $log->amount_effect);
    }

    public function test_editar_un_pago_guarda_el_monto_anterior(): void
    {
        $sale = $this->activeSale(680);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 680,
        ]);

        $this->actingAs($this->adminSucursal)->put(
            route('sucursal.workbench.payment.update', [$this->tenant->slug, $sale->id, $payment->id]),
            ['amount' => 380, 'method' => 'cash'],
        );

        $log = $this->lastLog(AuditEvent::PaymentUpdated);
        // Sin esta fila los $300 no existen en ninguna parte: la tabla `payments`
        // solo guarda el monto vigente. Es el agujero que abrió este módulo.
        $this->assertSame([680.0, 380.0], array_map('floatval', $log->changes['amount']));
        $this->assertSame(-300.0, (float) $log->amount_effect);
    }

    public function test_borrar_un_pago_lo_registra_completo_en_negativo(): void
    {
        $sale = $this->activeSale(300);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'transfer',
            'amount' => 300,
        ]);

        $this->actingAs($this->adminSucursal)->delete(
            route('sucursal.workbench.payment.destroy', [$this->tenant->slug, $sale->id, $payment->id]),
        );

        $log = $this->lastLog(AuditEvent::PaymentDeleted);
        $this->assertSame(-300.0, (float) $log->amount_effect);
        $this->assertSame('transfer', $log->changes['method']);
    }

    public function test_cancelar_una_venta_resta_su_total(): void
    {
        $sale = $this->activeSale(450);

        $sale->forceFill([
            'status' => SaleStatus::Cancelled,
            'cancel_reason' => 'Cliente se arrepintió',
        ])->save();

        $log = $this->lastLog(AuditEvent::Cancelled);
        $this->assertSame(-450.0, (float) $log->amount_effect);
        $this->assertSame('Cliente se arrepintió', $log->changes['reason']);
    }

    public function test_reabrir_una_venta_cobrada_no_mueve_dinero(): void
    {
        $sale = $this->activeSale(120);
        $sale->forceFill(['status' => SaleStatus::Completed, 'completed_at' => now()])->save();

        $sale->forceFill(['status' => SaleStatus::Active])->save();

        // Reabrir habilita mover dinero, no lo mueve: efecto nulo a propósito,
        // para que no ensucie el neto del periodo.
        $log = $this->lastLog(AuditEvent::Reopened);
        $this->assertNull($log->amount_effect);
    }

    public function test_asignar_y_quitar_cliente_se_ven_pero_no_suman(): void
    {
        $sale = $this->activeSale(80);
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Doña Mari',
            'phone' => '5551234567',
            'status' => 'active',
        ]);

        $service = app(AssignCustomerToSale::class);
        $service->execute($sale, $customer->id, $this->branch->id);

        // Pasar la venta a fiado saca el dinero del efectivo del día pero no lo
        // pierde: se ve en la lista, en gris, fuera del neto.
        $assigned = $this->lastLog(AuditEvent::CustomerAssigned);
        $this->assertNull($assigned->amount_effect);
        $this->assertSame('Doña Mari', $assigned->changes['customer']);

        $service->execute($sale->fresh(), null, $this->branch->id);

        $removed = $this->lastLog(AuditEvent::CustomerRemoved);
        $this->assertNull($removed->amount_effect);
        $this->assertSame('Doña Mari', $removed->changes['customer']);
    }

    public function test_el_registro_guarda_desde_donde_se_hizo_el_cambio(): void
    {
        $sale = $this->activeSale(680);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 680,
        ]);

        $this->actingAs($this->adminSucursal)
            ->withServerVariables([
                'REMOTE_ADDR' => '187.190.1.20',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ])
            ->put(
                route('sucursal.workbench.payment.update', [$this->tenant->slug, $sale->id, $payment->id]),
                ['amount' => 380, 'method' => 'cash'],
            );

        // Sin esto la pantalla no sirve para lo que se pidió: el dueño necesita
        // reconocer si un cambio hecho con su cuenta salió del equipo de siempre.
        $log = $this->lastLog(AuditEvent::PaymentUpdated);
        $this->assertSame('187.190.1.20', $log->ip_address);
        $this->assertStringContainsString('Windows', $log->user_agent);
    }

    public function test_un_cambio_sin_datos_de_equipo_no_inventa_contexto(): void
    {
        // El caso de un comando programado o un job de cola: hay request en el
        // contenedor, pero es sintético y no trae ni REMOTE_ADDR ni User-Agent.
        // Las dos columnas son nullable a propósito — «no sé de dónde vino» y
        // «vino de un equipo cualquiera» no son lo mismo.
        $sale = $this->activeSale(100);

        $request = HttpRequest::create('/interno', 'POST');
        $request->server->remove('REMOTE_ADDR');
        $request->server->remove('HTTP_USER_AGENT');
        $request->headers->remove('User-Agent');
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');

        app(AuditLogger::class)->logSaleReopened($sale, $this->adminSucursal->id);

        $log = $this->lastLog(AuditEvent::Reopened);
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
        $this->assertSame($this->adminSucursal->id, $log->user_id);
    }

    public function test_un_user_agent_larguisimo_no_revienta_la_columna(): void
    {
        // `user_agent` es varchar(255). Un navegador raro con una cadena más
        // larga tumbaría la escritura del pago entero, no solo su registro.
        $sale = $this->activeSale(200);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 200,
        ]);

        $this->actingAs($this->adminSucursal)
            ->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_USER_AGENT' => str_repeat('A', 400),
            ])
            ->put(
                route('sucursal.workbench.payment.update', [$this->tenant->slug, $sale->id, $payment->id]),
                ['amount' => 150, 'method' => 'cash'],
            );

        $log = $this->lastLog(AuditEvent::PaymentUpdated);
        $this->assertSame(255, mb_strlen($log->user_agent));
    }
}
