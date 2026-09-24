<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Models\User;
use App\Notifications\AgendaItemAssignedNotice;
use App\Notifications\AgendaReminderDue;
use App\Services\Agenda\AgendaReminderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Atender un recordatorio en la pantalla Agenda apaga su aviso en la isla, y
 * posponerlo, cambiarle la hora o el asignado lo deja listo para volver a avisar.
 */
class AgendaReminderLifecycleTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private AgendaItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();

        // Lo crea el admin de sucursal y se lo asigna al cajero: el aviso va al
        // cajero, y editar/borrar/cancelar le toca al admin (el creador).
        $this->item = AgendaItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminSucursal->id,
            'assigned_to_user_id' => $this->cajero->id,
            'remind_at' => now()->subMinutes(3),
        ]);
        app(AgendaReminderNotifier::class)->remind($this->item);
    }

    private function reminderRow(?User $user = null)
    {
        return ($user ?? $this->cajero)->notifications()
            ->where('type', AgendaReminderDue::class)
            ->whereRaw("(data::jsonb->>'item_id')::bigint = ?", [$this->item->id])
            ->sole();
    }

    private function assertReminderClosed(): void
    {
        $this->assertNotNull($this->reminderRow()->read_at);
    }

    private function assertReminderOpen(): void
    {
        $this->assertNull($this->reminderRow()->read_at);
    }

    /** Lo que el modal manda en un PUT: todos los campos, con la hora del input datetime-local. */
    private function putPayload(array $overrides = []): array
    {
        $item = $this->item->fresh();

        return array_merge([
            'type' => $item->type->value,
            'title' => $item->title,
            'scope' => $item->scope->value,
            'assigned_to_user_id' => $item->assigned_to_user_id,
            'remind_at' => $item->remind_at?->format('Y-m-d\TH:i'),
        ], $overrides);
    }

    private function url(string $name): string
    {
        return route($name, [$this->tenant->slug, $this->item->id]);
    }

    public function test_complete_closes_the_notice(): void
    {
        $this->actingAs($this->cajero)->patch($this->url('agenda.complete'))->assertRedirect();

        $this->assertReminderClosed();
    }

    public function test_cancel_closes_the_notice(): void
    {
        $this->actingAs($this->adminSucursal)->patch($this->url('agenda.cancel'))->assertRedirect();

        $this->assertReminderClosed();
    }

    public function test_mark_seen_closes_the_notice(): void
    {
        $this->actingAs($this->cajero)->patch($this->url('agenda.visto'))->assertRedirect();

        $this->assertReminderClosed();
    }

    public function test_destroy_closes_the_notice(): void
    {
        $this->actingAs($this->adminSucursal)->delete($this->url('agenda.destroy'))->assertRedirect();

        $this->assertReminderClosed();
    }

    public function test_snooze_closes_the_notice_and_rearms(): void
    {
        $this->actingAs($this->cajero)
            ->patch($this->url('agenda.snooze'), ['minutes' => 30])
            ->assertRedirect();

        $this->assertReminderClosed();
        $fresh = $this->item->fresh();
        $this->assertNull($fresh->reminder_notified_at);
        $this->assertNull($fresh->reminder_seen_at);
    }

    public function test_update_changing_remind_at_closes_and_rearms(): void
    {
        $this->actingAs($this->adminSucursal)
            ->put($this->url('agenda.update'), $this->putPayload([
                'remind_at' => now()->addHour()->format('Y-m-d\TH:i'),
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertReminderClosed();
        $this->assertNull($this->item->fresh()->reminder_notified_at);
    }

    public function test_update_with_the_same_values_touches_nothing(): void
    {
        // El modal manda la hora sin segundos: la misma hora en otro formato no
        // es un cambio.
        $this->actingAs($this->adminSucursal)
            ->put($this->url('agenda.update'), $this->putPayload(['title' => 'Otro título']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertReminderOpen();
        $this->assertNotNull($this->item->fresh()->reminder_notified_at);
        $this->assertSame(0, $this->cajero->notifications()->where('type', AgendaItemAssignedNotice::class)->count());
    }

    public function test_update_reassigning_moves_the_reminder_to_the_new_assignee(): void
    {
        $this->actingAs($this->adminSucursal)
            ->put($this->url('agenda.update'), $this->putPayload(['assigned_to_user_id' => $this->adminEmpresa->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertReminderClosed();
        $this->assertNull($this->item->fresh()->reminder_notified_at);
        $this->assertSame(1, $this->adminEmpresa->notifications()->where('type', AgendaItemAssignedNotice::class)->count());
        $this->assertSame(0, $this->adminSucursal->notifications()->count());
    }

    public function test_update_assigning_to_oneself_does_not_notify(): void
    {
        $this->actingAs($this->adminSucursal)
            ->put($this->url('agenda.update'), $this->putPayload(['assigned_to_user_id' => $this->adminSucursal->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertReminderClosed();
        $this->assertSame(0, $this->adminSucursal->notifications()->count());
    }

    public function test_store_with_another_assignee_notifies_them(): void
    {
        $this->actingAs($this->adminSucursal)
            ->post(route('agenda.store', $this->tenant->slug), [
                'type' => 'task', 'title' => 'Contar caja', 'scope' => 'personal',
                'assigned_to_user_id' => $this->cajero->id,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $row = $this->cajero->notifications()->where('type', AgendaItemAssignedNotice::class)->sole();
        $this->assertSame('Contar caja', AgendaItem::find($row->data['item_id'])->title);
    }

    public function test_completing_a_recurring_item_clones_it_unreminded(): void
    {
        $this->item->forceFill([
            'recurrence' => 'daily',
            'starts_at' => now()->subMinutes(2),
            'reminder_seen_at' => now(),
        ])->save();

        $this->actingAs($this->cajero)->patch($this->url('agenda.complete'))->assertRedirect();

        $clone = AgendaItem::query()->whereKeyNot($this->item->id)->sole();
        $this->assertNull($clone->completed_at);
        $this->assertNull($clone->reminder_notified_at);
        $this->assertNull($clone->reminder_seen_at);
    }

    public function test_the_old_agenda_bell_endpoint_is_retired(): void
    {
        $this->assertFalse(Route::has('agenda.notificaciones'));

        $this->actingAs($this->cajero)
            ->getJson(route('agenda.alerts', $this->tenant->slug))
            ->assertOk();
    }
}
