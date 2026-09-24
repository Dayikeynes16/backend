<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgendaReminderDue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El comando por minuto: avisa una sola vez cada recordatorio vencido y sin
 * atender, de cualquier tenant, y un envío fallido no corta a los demás.
 */
class DispatchAgendaRemindersCommandTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function item(array $attrs = []): AgendaItem
    {
        return AgendaItem::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->cajero->id,
            'remind_at' => now()->subMinute(),
        ], $attrs));
    }

    private function reminderRows()
    {
        return DatabaseNotification::query()->where('type', AgendaReminderDue::class);
    }

    public function test_it_reminds_a_due_item_once(): void
    {
        $item = $this->item();

        $this->artisan('agenda:dispatch-reminders')->assertSuccessful();
        $this->artisan('agenda:dispatch-reminders')->assertSuccessful();

        $this->assertSame(1, $this->reminderRows()->count());
        $this->assertSame($this->cajero->id, $this->reminderRows()->sole()->notifiable_id);
        $this->assertNotNull($item->fresh()->reminder_notified_at);
    }

    public function test_it_skips_items_that_do_not_need_a_reminder(): void
    {
        $this->item(['completed_at' => now()]);
        $this->item(['cancelled_at' => now()]);
        $this->item(['reminder_seen_at' => now()]);
        $this->item(['remind_at' => now()->addHour()]);
        $this->item(['remind_at' => null]);
        $this->item(['reminder_notified_at' => now()->subMinutes(5)]);
        $this->item()->delete();

        $this->artisan('agenda:dispatch-reminders')->assertSuccessful();

        $this->assertSame(0, $this->reminderRows()->count());
    }

    public function test_each_tenant_gets_its_own_reminders(): void
    {
        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra', 'status' => 'active']);
        $stranger = User::create([
            'tenant_id' => $other->id, 'name' => 'Ajeno', 'email' => 'ajeno@test.local',
            'password' => bcrypt('password'),
        ]);
        $mine = $this->item();
        $theirs = $this->item(['tenant_id' => $other->id, 'user_id' => $stranger->id]);

        $this->artisan('agenda:dispatch-reminders')->assertSuccessful();

        $this->assertSame([$mine->id], $this->cajero->notifications()->get()->pluck('data.item_id')->all());
        $this->assertSame([$theirs->id], $stranger->notifications()->get()->pluck('data.item_id')->all());
    }

    public function test_a_failing_broadcast_does_not_stop_the_others(): void
    {
        $this->breakBroadcasting();
        $first = $this->item();
        $second = $this->item(['user_id' => $this->adminSucursal->id]);

        $this->artisan('agenda:dispatch-reminders')->assertSuccessful();

        // La fila se guarda antes del broadcast: ambos quedan en la bandeja.
        $this->assertSame(2, $this->reminderRows()->count());
        $this->assertNotNull($first->fresh()->reminder_notified_at);
        $this->assertNotNull($second->fresh()->reminder_notified_at);
    }

    public function test_it_is_scheduled_every_minute(): void
    {
        // withSchedule() se engancha a Artisan::starting: hay que arrancar la
        // consola para que el callback registre las tareas.
        Artisan::all();

        $scheduled = collect(app(Schedule::class)->events())->contains(
            fn ($e) => str_contains((string) $e->command, 'agenda:dispatch-reminders') && $e->expression === '* * * * *'
        );

        $this->assertTrue($scheduled);
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
                throw new RuntimeException('Reverb caído.');
            }
        });

        config([
            'broadcasting.default' => 'boom',
            'broadcasting.connections.boom' => ['driver' => 'boom'],
        ]);
    }
}
