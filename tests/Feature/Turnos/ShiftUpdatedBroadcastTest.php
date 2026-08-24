<?php

namespace Tests\Feature\Turnos;

use App\Events\ShiftUpdated;
use App\Models\CashWithdrawal;
use App\Services\ShiftService;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El panel de turno del hub se sostenía con un sondeo cada 12 s. Con estos
 * avisos el sondeo pasa a ser red de seguridad y baja a 45 s cuando hay socket.
 */
class ShiftUpdatedBroadcastTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ShiftService $shifts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->shifts = app(ShiftService::class);
    }

    public function test_opening_a_shift_announces_it(): void
    {
        Event::fake([ShiftUpdated::class]);

        $this->shifts->open($this->cajero, 500);

        Event::assertDispatched(ShiftUpdated::class, fn ($e) => $e->reason === 'opened');
    }

    public function test_a_retried_open_re_announces_the_existing_shift(): void
    {
        // La apertura del hub es idempotente por client_reference. El reintento
        // llega cuando el primero no terminó bien, que es cuando el aviso se
        // perdió: sin repetirlo, el turno queda abierto sin que nadie se entere.
        $first = $this->shifts->open($this->cajero, 500, null, 'ref-turno-1');

        Event::fake([ShiftUpdated::class]);

        $second = $this->shifts->open($this->cajero, 500, null, 'ref-turno-1');

        $this->assertSame($first->id, $second->id);
        Event::assertDispatched(ShiftUpdated::class, fn ($e) => $e->shift->id === $first->id);
    }

    public function test_closing_a_shift_announces_it(): void
    {
        $this->shifts->open($this->cajero, 500);

        Event::fake([ShiftUpdated::class]);
        $this->shifts->close($this->cajero, ['declared_amount' => 500]);

        Event::assertDispatched(ShiftUpdated::class, fn ($e) => $e->reason === 'closed');
    }

    public function test_a_withdrawal_announces_it(): void
    {
        $this->shifts->open($this->cajero, 500);

        Event::fake([ShiftUpdated::class]);
        $this->shifts->addWithdrawal($this->cajero, 100, 'Compra de bolsas');

        Event::assertDispatched(ShiftUpdated::class, fn ($e) => $e->reason === 'withdrawal');
    }

    public function test_removing_a_withdrawal_announces_it(): void
    {
        $this->shifts->open($this->cajero, 500);
        $withdrawal = $this->shifts->addWithdrawal($this->cajero, 100, 'Compra de bolsas');

        Event::fake([ShiftUpdated::class]);
        $this->shifts->removeWithdrawal($this->cajero, CashWithdrawal::find($withdrawal->id));

        Event::assertDispatched(ShiftUpdated::class, fn ($e) => $e->reason === 'withdrawal');
    }

    public function test_the_payload_carries_no_money(): void
    {
        /*
         * Va por el canal de la sucursal, que comparten todos sus usuarios. Si
         * llevara cifras, el efectivo de un cajero se asomaría al panel de otro.
         * Quien lo recibe pide su propio turno por HTTP.
         */
        $shift = $this->shifts->open($this->cajero, 1500);

        $payload = (new ShiftUpdated($shift, 'opened'))->broadcastWith();

        $this->assertSame(['shift_id', 'user_id', 'reason'], array_keys($payload));
        $this->assertSame($shift->id, $payload['shift_id']);
    }

    public function test_it_travels_on_the_branch_channel(): void
    {
        $shift = $this->shifts->open($this->cajero, 0);

        $this->assertSame(
            "private-sucursal.{$this->branch->id}",
            (new ShiftUpdated($shift, 'opened'))->broadcastOn()->name,
        );
    }

    public function test_a_broken_reverb_does_not_break_opening_a_shift(): void
    {
        $this->mock(Factory::class, function ($mock) {
            $mock->shouldReceive('queue')->andThrow(new \RuntimeException('reverb down'));
        });

        $shift = $this->shifts->open($this->cajero, 300);

        $this->assertNotNull($shift->id);
        $this->assertNull($shift->closed_at);
    }

    public function test_a_broken_reverb_does_not_break_closing_a_shift(): void
    {
        $this->shifts->open($this->cajero, 300);

        $this->mock(Factory::class, function ($mock) {
            $mock->shouldReceive('queue')->andThrow(new \RuntimeException('reverb down'));
        });

        // El corte es el momento en que menos se puede perder una operación.
        $closed = $this->shifts->close($this->cajero, ['declared_amount' => 300]);

        $this->assertNotNull($closed->closed_at);
    }
}
