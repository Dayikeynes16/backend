<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgendaItemAssignedNotice;
use App\Notifications\AgendaReminderDue;
use App\Services\Agenda\AgendaReminderNotifier;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La agenda como fuente de avisos: el recordatorio va a quien puede atenderlo,
 * se avisa una vez y se apaga cuando el ítem se atiende.
 */
class AgendaReminderNotifierTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private AgendaReminderNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->notifier = app(AgendaReminderNotifier::class);
    }

    private function item(array $attrs = []): AgendaItem
    {
        return AgendaItem::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->cajero->id,
            'remind_at' => now()->subMinute(),
        ], $attrs));
    }

    private function remindersFor(User $user, ?AgendaItem $item = null)
    {
        return $user->notifications()
            ->where('type', AgendaReminderDue::class)
            ->when($item, fn ($q) => $q->whereRaw("(data::jsonb->>'item_id')::bigint = ?", [$item->id]));
    }

    public function test_recipient_is_the_assignee_when_there_is_one(): void
    {
        $item = $this->item(['assigned_to_user_id' => $this->adminSucursal->id]);

        $this->assertTrue($this->notifier->recipient($item)->is($this->adminSucursal));
    }

    public function test_recipient_is_the_creator_when_nobody_is_assigned(): void
    {
        $this->assertTrue($this->notifier->recipient($this->item())->is($this->cajero));
    }

    public function test_recipient_is_never_a_user_of_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Otra', 'slug' => 'otra', 'status' => 'active']);
        $stranger = User::create([
            'tenant_id' => $other->id, 'name' => 'Ajeno', 'email' => 'ajeno@test.local',
            'password' => bcrypt('password'),
        ]);

        $item = $this->item(['assigned_to_user_id' => $stranger->id]);

        $this->assertNull($this->notifier->recipient($item));
    }

    public function test_remind_stores_the_notice_and_marks_the_item(): void
    {
        $item = $this->item();

        $this->notifier->remind($item);

        $row = $this->remindersFor($this->cajero)->sole();
        $this->assertSame($item->id, $row->data['item_id']);
        $this->assertSame('agenda.reminder.due', $row->data['type']);
        $this->assertSame('action', $row->data['level']);
        $this->assertNotNull($item->fresh()->reminder_notified_at);
    }

    public function test_remind_survives_a_broadcast_failure_and_keeps_the_row(): void
    {
        $this->breakBroadcasting();
        $item = $this->item();

        $this->notifier->remind($item);

        $this->assertSame(1, $this->remindersFor($this->cajero, $item)->count());
        $this->assertNotNull($item->fresh()->reminder_notified_at);
    }

    public function test_assigned_notifies_the_assignee(): void
    {
        $item = $this->item(['user_id' => $this->adminSucursal->id, 'assigned_to_user_id' => $this->cajero->id]);

        $this->notifier->assigned($item, $this->adminSucursal);

        $row = $this->cajero->notifications()->where('type', AgendaItemAssignedNotice::class)->sole();
        $this->assertSame('agenda.item.assigned', $row->data['type']);
        $this->assertSame($item->id, $row->data['item_id']);
        $this->assertSame($this->adminSucursal->name, $row->data['assigned_by']);
    }

    public function test_assigned_does_not_notify_who_assigns_to_themselves(): void
    {
        $item = $this->item(['assigned_to_user_id' => $this->cajero->id]);

        $this->notifier->assigned($item, $this->cajero);

        $this->assertSame(0, $this->cajero->notifications()->count());
    }

    public function test_close_marks_read_only_the_notices_of_that_item(): void
    {
        $item = $this->item();
        $other = $this->item();
        $this->notifier->remind($item);
        $this->notifier->remind($other);

        $this->assertSame(1, $this->notifier->close($item));

        $this->assertNotNull($this->remindersFor($this->cajero, $item)->sole()->read_at);
        $this->assertNull($this->remindersFor($this->cajero, $other)->sole()->read_at);
        $this->assertSame(0, $this->notifier->close($item));
    }

    public function test_rearm_clears_both_marks(): void
    {
        $item = $this->item(['reminder_seen_at' => now()]);
        $this->notifier->remind($item);

        $this->notifier->rearm($item);

        $fresh = $item->fresh();
        $this->assertNull($fresh->reminder_notified_at);
        $this->assertNull($fresh->reminder_seen_at);
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
