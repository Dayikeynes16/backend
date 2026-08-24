<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Lo mismo que `SaleMovementWritesTest`, pero entrando por la API del hub.
 *
 * Existe por separado porque el hub es la superficie desde la que se opera la
 * caja en la sucursal: si sus escrituras no dejaran rastro, la pantalla mentiría
 * justo donde más importa.
 */
class HubSaleMovementWritesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function token(): string
    {
        return $this->adminSucursal->createToken('hub')->plainTextToken;
    }

    /** @return array{0: Sale, 1: Payment} */
    private function saleWithPayment(float $amount): array
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $amount,
            'amount_paid' => $amount,
            'amount_pending' => 0,
            'origin' => 'api',
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
        ]);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => $amount,
        ]);

        return [$sale, $payment];
    }

    private function lastLog(AuditEvent $event): AuditLog
    {
        $log = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', (new Sale)->getMorphClass())
            ->where('event', $event->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, "El hub no registró el evento {$event->value}.");

        return $log;
    }

    public function test_el_hub_registra_la_edicion_de_un_pago(): void
    {
        [$sale, $payment] = $this->saleWithPayment(500);

        $this->withToken($this->token())
            ->putJson("/api/v1/hub/sales/{$sale->id}/payments/{$payment->id}", [
                'amount' => 200,
                'method' => 'cash',
            ])->assertOk();

        $log = $this->lastLog(AuditEvent::PaymentUpdated);
        $this->assertSame([500.0, 200.0], array_map('floatval', $log->changes['amount']));
        $this->assertSame(-300.0, (float) $log->amount_effect);
        $this->assertSame($this->branch->id, $log->branch_id);
        $this->assertSame($this->adminSucursal->id, $log->user_id);
    }

    public function test_el_hub_registra_el_borrado_de_un_pago(): void
    {
        [$sale, $payment] = $this->saleWithPayment(150);

        $this->withToken($this->token())
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/payments/{$payment->id}")
            ->assertOk();

        $log = $this->lastLog(AuditEvent::PaymentDeleted);
        $this->assertSame(-150.0, (float) $log->amount_effect);
        $this->assertSame('cash', $log->changes['method']);
    }
}
