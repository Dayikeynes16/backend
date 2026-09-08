<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\OrderLinkService;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Reverb caído no puede tumbar trabajo ya hecho.
 *
 * Todos los eventos son `ShouldBroadcastNow`: la llamada HTTP al servidor de
 * websockets ocurre dentro de la misma petición. Si esa llamada revienta y
 * nadie la contiene, una operación cuyo efecto real ya está en la base de
 * datos responde 500 — y quien la pidió (una báscula, el hub, un cajero)
 * concluye que falló y la repite.
 *
 * El aviso en vivo es best-effort: quien no lo recibe se pone al día leyendo
 * por HTTP. Perderlo nunca debe costar más que el propio aviso.
 */
class BroadcastResilienceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->breakBroadcasting();
    }

    /** Sustituye el transporte por uno que siempre falla, como un Reverb caído. */
    private function breakBroadcasting(): void
    {
        Broadcast::extend('boom', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Reverb no responde.');
            }
        });

        config()->set('broadcasting.default', 'boom');
    }

    public function test_locking_a_sale_survives_a_broadcast_failure(): void
    {
        $sale = $this->scaleSale();

        $this->actingAs($this->adminSucursal)
            ->postJson(route('sucursal.sale.lock', [$this->tenant->slug, $sale->id]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame($this->adminSucursal->id, $sale->fresh()->locked_by);
    }

    public function test_unlocking_a_sale_survives_a_broadcast_failure(): void
    {
        $sale = $this->scaleSale();
        $sale->updateQuietly(['locked_by' => $this->adminSucursal->id, 'locked_at' => now()]);

        $this->actingAs($this->adminSucursal)
            ->postJson(route('sucursal.sale.unlock', [$this->tenant->slug, $sale->id]))
            ->assertOk();

        $this->assertNull($sale->fresh()->locked_by);
    }

    public function test_assigning_an_agenda_item_survives_a_broadcast_failure(): void
    {
        $this->actingAs($this->adminSucursal)
            ->post(route('agenda.store', $this->tenant->slug), [
                'type' => 'task',
                'title' => 'Pedir monedas al banco',
                'scope' => 'personal',
                'assigned_to_user_id' => $this->cajero->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('agenda_items', [
            'title' => 'Pedir monedas al banco',
            'assigned_to_user_id' => $this->cajero->id,
        ]);
    }

    public function test_linking_a_web_order_survives_a_broadcast_failure(): void
    {
        $web = $this->webOrder();
        $scale = $this->scaleSale();

        app(OrderLinkService::class)->link($scale, $web);

        $this->assertSame($web->id, $scale->fresh()->linked_order_id);
        $this->assertSame(SaleStatus::Fulfilled, $web->fresh()->status);
    }

    public function test_unlinking_a_web_order_survives_a_broadcast_failure(): void
    {
        $web = $this->webOrder();
        $scale = $this->scaleSale();
        $service = app(OrderLinkService::class);
        $service->link($scale, $web);

        $service->unlink($scale->fresh());

        $this->assertNull($scale->fresh()->linked_order_id);
        $this->assertSame(SaleStatus::Pending, $web->fresh()->status);
    }

    private function scaleSale(): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'API-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 200,
            'amount_paid' => 0,
            'amount_pending' => 200,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Carne',
            'unit_type' => 'kg',
            'quantity' => 1,
            'unit_price' => 200,
            'original_unit_price' => 200,
            'subtotal' => 200,
        ]);

        return $sale;
    }

    private function webOrder(): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ]);
    }
}
