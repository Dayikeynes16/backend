<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Services\SaleMovementsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La lectura de Movimientos: una fila por venta, ordenada por lo que se le quitó.
 *
 * Las filas de `audit_logs` se crean a mano. Que las acciones reales las
 * produzcan ya lo garantizan `SaleMovementWritesTest` y su par del hub; montar
 * aquí las escrituras solo añadiría ruido y lentitud a lo que se quiere fijar,
 * que es cómo se leen.
 */
class SaleMovementsQueryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function sale(float $total = 500): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => $total,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    private function logMovement(Sale $sale, AuditEvent $event, ?float $effect, ?Carbon $at = null, ?int $branchId = null): AuditLog
    {
        return AuditLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'auditable_type' => (new Sale)->getMorphClass(),
            'auditable_id' => $sale->id,
            'user_id' => $this->adminSucursal->id,
            'event' => $event->value,
            'changes' => [],
            'amount_effect' => $effect,
            'created_at' => $at ?? now(),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(array $filters = [], ?int $forcedBranchId = null)
    {
        return collect(app(SaleMovementsQuery::class)->run($filters, $forcedBranchId)['sales']->items());
    }

    public function test_cobrar_una_venta_no_es_un_movimiento(): void
    {
        // `payment_added` solo lo escribe el módulo de compras. Si algún día se
        // registrara también al cobrar, esta lista tendría que decidirse a
        // propósito y no por herencia: cada venta cobrada aparecería en la
        // pantalla, que es justo el ruido que existe para evitar.
        $this->assertNotContains('payment_added', AuditEvent::saleMovements());
        $this->assertContains('payment_updated', AuditEvent::saleMovements());
        $this->assertContains('payment_deleted', AuditEvent::saleMovements());
    }

    public function test_agrupa_por_venta_y_cuenta_sus_movimientos(): void
    {
        $sale = $this->sale();
        $this->logMovement($sale, AuditEvent::ItemUpdated, -40);
        $this->logMovement($sale, AuditEvent::PaymentUpdated, -60);

        $rows = $this->rows();

        // Se pagina por venta, no por evento: una venta con doce cambios no debe
        // empujar al resto fuera de la página.
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()['movement_count']);
        $this->assertSame(-100.0, $rows->first()['net_effect']);
        $this->assertCount(2, $rows->first()['movements']);
    }

    public function test_el_neto_deja_fuera_los_eventos_no_monetarios(): void
    {
        $sale = $this->sale();
        $this->logMovement($sale, AuditEvent::ItemRemoved, -50);
        $this->logMovement($sale, AuditEvent::CustomerAssigned, null);

        $result = app(SaleMovementsQuery::class)->run([]);

        // Pasar la venta a fiado saca el dinero del efectivo del día pero no lo
        // pierde: contarlo daría una pérdida que no ocurrió.
        $this->assertSame(-50.0, $result['summary']['net_effect']);
        $this->assertSame(2, $result['summary']['movement_count']);
        $this->assertSame(1, $result['summary']['sales_touched']);
    }

    public function test_ordena_por_el_dinero_que_se_quito(): void
    {
        $poca = $this->sale();
        $mucha = $this->sale();
        $this->logMovement($poca, AuditEvent::ItemUpdated, -10);
        $this->logMovement($mucha, AuditEvent::PaymentDeleted, -400);

        $rows = $this->rows();

        $this->assertSame($mucha->id, $rows->first()['sale_id']);
    }

    public function test_marca_precio_y_pago_dentro_de_la_misma_hora(): void
    {
        $sale = $this->sale();
        $this->logMovement($sale, AuditEvent::ItemUpdated, -300, now()->setTime(14, 0));
        $this->logMovement($sale, AuditEvent::PaymentUpdated, -300, now()->setTime(14, 20));

        // Bajar un precio y bajar un pago seguidos es la firma de lo que se busca.
        $this->assertTrue($this->rows()->first()['suspicious']);
    }

    public function test_no_marca_un_cambio_suelto(): void
    {
        $sale = $this->sale();
        $this->logMovement($sale, AuditEvent::ItemUpdated, -300, now()->setTime(14, 0));

        // Un cambio de precio solo casi siempre es una corrección legítima;
        // marcarlo todo sería igual que no marcar nada.
        $this->assertFalse($this->rows()->first()['suspicious']);
    }

    public function test_no_marca_cambios_separados_por_horas(): void
    {
        $sale = $this->sale();
        $this->logMovement($sale, AuditEvent::ItemUpdated, -300, now()->setTime(9, 0));
        $this->logMovement($sale, AuditEvent::PaymentUpdated, -300, now()->setTime(18, 0));

        $this->assertFalse($this->rows()->first()['suspicious']);
    }

    public function test_la_sucursal_forzada_gana_sobre_la_de_la_url(): void
    {
        $mia = $this->sale();
        $ajena = $this->sale();
        $this->logMovement($mia, AuditEvent::ItemUpdated, -20);
        $this->logMovement($ajena, AuditEvent::ItemUpdated, -999, null, $this->secondBranch->id);

        // El admin-sucursal no elige sucursal: mandar otra por query string no
        // puede ampliarle el alcance.
        $rows = $this->rows(['branch_id' => $this->secondBranch->id], $this->branch->id);

        $this->assertCount(1, $rows);
        $this->assertSame($mia->id, $rows->first()['sale_id']);
    }

    public function test_el_filtro_por_evento_acota_la_lista(): void
    {
        $sale = $this->sale();
        $this->logMovement($sale, AuditEvent::ItemUpdated, -40);
        $this->logMovement($sale, AuditEvent::PaymentDeleted, -60);

        $result = app(SaleMovementsQuery::class)->run(['event' => 'payment_deleted']);

        $this->assertSame(1, $result['summary']['movement_count']);
        $this->assertSame(-60.0, $result['summary']['net_effect']);
    }

    public function test_fuera_del_rango_de_fechas_no_aparece(): void
    {
        $sale = $this->sale();
        $this->logMovement($sale, AuditEvent::PaymentDeleted, -500, now()->subDays(3));

        // Por defecto la pantalla muestra hoy.
        $this->assertCount(0, $this->rows());

        $conRango = $this->rows([
            'from' => now()->subDays(5)->toDateString(),
            'to' => now()->toDateString(),
        ]);
        $this->assertCount(1, $conRango);
    }

    public function test_la_ventana_de_la_marca_es_de_una_hora_exacta(): void
    {
        // El borde importa: la ventana es la única cifra que decide si un par de
        // cambios se leen como un mismo acto, y un refactor podría moverla sin
        // que ningún otro test lo notara.
        $justo = $this->sale();
        $this->logMovement($justo, AuditEvent::ItemUpdated, -100, now()->setTime(14, 0));
        $this->logMovement($justo, AuditEvent::PaymentUpdated, -100, now()->setTime(15, 0));

        $pasado = $this->sale();
        $this->logMovement($pasado, AuditEvent::ItemUpdated, -100, now()->setTime(14, 0));
        $this->logMovement($pasado, AuditEvent::PaymentUpdated, -100, now()->setTime(15, 1));

        $rows = $this->rows()->keyBy('sale_id');

        $this->assertTrue($rows[$justo->id]['suspicious'], 'A los 60 minutos justos todavía es el mismo acto.');
        $this->assertFalse($rows[$pasado->id]['suspicious'], 'A los 61 minutos ya no.');
    }
}
