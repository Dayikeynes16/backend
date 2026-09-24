<?php

namespace App\Notifications;

use App\Models\AgendaItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Venció un recordatorio de agenda. Va sólo a quien puede atenderlo: el
 * asignado o, si no hay, quien creó la tarea (ver AgendaReminderNotifier).
 *
 * Es `action`: un recordatorio que se recoge solo no recuerda nada. Se persiste
 * porque puede vencer con la sesión cerrada y tiene que encontrarse al entrar.
 */
class AgendaReminderDue extends Notification
{
    use Queueable;

    public function __construct(public AgendaItem $item) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        // Los tenants no tienen zona propia: la hora se muestra en la de la app.
        $at = $this->item->remind_at?->copy()->timezone(config('app.timezone'));
        $when = $at?->isToday() ? 'a las '.$at->format('H:i') : 'el '.$at?->translatedFormat('j M, H:i');

        return [
            'type' => 'agenda.reminder.due',
            'level' => 'action',
            'title' => $this->item->title,
            'body' => $at ? "Vencía {$when}" : 'Recordatorio',
            'item_id' => $this->item->id,
            'priority' => $this->item->priority?->value,
            'remind_at' => $this->item->remind_at?->toIso8601String(),
        ];
    }

    /**
     * El `type` que viaja por el socket. Sin esto Laravel pone el nombre de la
     * clase y el aviso en vivo no coincide con el guardado.
     */
    public function broadcastType(): string
    {
        return 'agenda.reminder.due';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Síncrono: BroadcastNotificationCreated es ShouldBroadcast (encolado) y
        // producción no corre colas, así que sin esto el aviso no salía en vivo.
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
