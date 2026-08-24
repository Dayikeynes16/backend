<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Services\SaleItemEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
