<?php

namespace App\Notifications;

use App\Models\AgendaItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Alguien le asignó una tarea de agenda a este usuario. Sustituye al evento
 * `AgendaItemAssigned`, que se emitía y nadie escuchaba: como aviso guardado,
 * llega en vivo y además queda en la bandeja si no estaba conectado.
 */
class AgendaItemAssignedNotice extends Notification
{
    use Queueable;

    public function __construct(public AgendaItem $item, public string $assignedBy) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'agenda.item.assigned',
            'level' => 'important',
            'title' => 'Te asignaron una tarea',
            'body' => "{$this->assignedBy}: {$this->item->title}",
            'item_id' => $this->item->id,
            'assigned_by' => $this->assignedBy,
        ];
    }

    /**
     * El `type` que viaja por el socket. Sin esto Laravel pone el nombre de la
     * clase y el aviso en vivo no coincide con el guardado.
     */
    public function broadcastType(): string
    {
        return 'agenda.item.assigned';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Síncrono: BroadcastNotificationCreated es ShouldBroadcast (encolado) y
        // producción no corre colas, así que sin esto el aviso no salía en vivo.
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
