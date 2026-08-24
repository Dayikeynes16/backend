<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La lectura de Movimientos: una fila por venta, ordenada por lo que se le quitó.
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
}
