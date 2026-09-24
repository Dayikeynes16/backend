<?php

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Un equipo dejó de reportar dentro de la ventana de operación. Persistente: si nadie estaba mirando, lo encuentra al entrar. */
class DeviceSilent extends Notification
{
    use Queueable;

    public function __construct(public Device $device) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'device.silent',
            'level' => 'important',
            'title' => 'Equipo sin reportar',
            'body' => "{$this->device->displayName()} no reporta desde las {$this->device->last_seen_at->timezone(config('app.timezone'))->format('H:i')}.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
            'last_seen_at' => $this->device->last_seen_at->toIso8601String(),
        ];
    }

    /**
     * El `type` que viaja por el socket. Sin esto Laravel pone el nombre de la
     * clase y el aviso en vivo no coincide con el guardado.
     */
    public function broadcastType(): string
    {
        return 'device.silent';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Síncrono: BroadcastNotificationCreated es ShouldBroadcast (encolado) y
        // producción no corre colas, así que sin esto el aviso no salía en vivo.
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
