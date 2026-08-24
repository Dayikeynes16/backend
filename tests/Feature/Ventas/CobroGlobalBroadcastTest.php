<?php

namespace Tests\Feature\Ventas;

use App\Enums\SaleStatus;
use App\Events\CustomerGlobalPaymentChanged;
use App\Events\SaleUpdated;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\CustomerGlobalPaymentService;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Un cobro global FIFO debe avisar UNA vez, no una por venta saldada.
 *
 * Cada aviso provocaba una recarga completa de la mesa de trabajo, así que
 * saldar diez ventas significaba diez recargas seguidas en todas las pantallas
 * de la sucursal.
 */
class CobroGlobalBroadcastTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente fiado',
            'status' => 'active',
        ]);
    }

    private function makeSaleWithBalance(float $total): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'folio' => 'V-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'status' => SaleStatus::Active,
            'origin' => 'api',
        ]);
    }

    public function test_a_global_payment_announces_once_for_every_sale_it_touches(): void
    {
        $sales = collect(range(1, 4))->map(fn () => $this->makeSaleWithBalance(100));

        Event::fake([CustomerGlobalPaymentChanged::class, SaleUpdated::class]);

        $service = app(CustomerGlobalPaymentService::class);
        $result = $service->apply($this->customer, $this->cajero, [
            'amount_received' => 400.0,
            'method' => 'cash',
        ]);
        $service->broadcastPaymentChange($result['customer_payment'], $result['affected_sale_ids']);

        $this->assertCount(4, $result['affected_sale_ids']);

        // Un solo aviso, no cuatro.
        Event::assertDispatchedTimes(CustomerGlobalPaymentChanged::class, 1);

        Event::assertDispatched(CustomerGlobalPaymentChanged::class, function ($e) use ($sales) {
            return $e->action === 'applied'
                && count($e->saleIds) === 4
                && $e->payment->branch_id === $this->branch->id
                && collect($e->saleIds)->diff($sales->pluck('id'))->isEmpty();
        });
    }

    public function test_the_summary_travels_in_the_payload(): void
    {
        $this->makeSaleWithBalance(250);

        $service = app(CustomerGlobalPaymentService::class);
        $result = $service->apply($this->customer, $this->cajero, [
            'amount_received' => 250.0,
            'method' => 'cash',
        ]);

        $event = new CustomerGlobalPaymentChanged(
            $result['customer_payment'],
            $result['affected_sale_ids'],
        );

        $payload = $event->broadcastWith();

        $this->assertSame('applied', $payload['action']);
        $this->assertSame(1, $payload['sales_affected_count']);
        $this->assertSame(250.0, $payload['amount_applied']);
        $this->assertSame($this->customer->id, $payload['customer_id']);
    }

    public function test_the_channel_is_the_branch_of_the_payment(): void
    {
        // Aislamiento: el cobro no puede anunciarse en la sucursal de otro.
        $this->makeSaleWithBalance(50);

        $service = app(CustomerGlobalPaymentService::class);
        $result = $service->apply($this->customer, $this->cajero, [
            'amount_received' => 50.0,
            'method' => 'cash',
        ]);

        $event = new CustomerGlobalPaymentChanged($result['customer_payment'], $result['affected_sale_ids']);

        $this->assertSame("private-sucursal.{$this->branch->id}", $event->broadcastOn()->name);
    }

    public function test_a_broken_reverb_does_not_break_the_collection(): void
    {
        $this->makeSaleWithBalance(100);

        $this->mock(Factory::class, function ($mock) {
            $mock->shouldReceive('queue')->andThrow(new \RuntimeException('reverb down'));
        });

        $service = app(CustomerGlobalPaymentService::class);
        $result = $service->apply($this->customer, $this->cajero, [
            'amount_received' => 100.0,
            'method' => 'cash',
        ]);

        // No debe propagar: el cobro ya está registrado.
        $service->broadcastPaymentChange($result['customer_payment'], $result['affected_sale_ids']);

        $this->assertSame(100.0, (float) $result['customer_payment']->amount_applied);
    }
}
