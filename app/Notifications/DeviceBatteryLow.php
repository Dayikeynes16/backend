<?php

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Un equipo está por descargarse. Persistente: si nadie estaba mirando, lo encuentra al entrar. */
class DeviceBatteryLow extends Notification
{
    use Queueable;

    public function __construct(public Device $device, public string $severity) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $level = $this->device->battery_level;
        $name = $this->device->displayName();
        $critical = $this->severity === 'critical';

        return [
            'type' => 'device.battery.low',
            'level' => 'important',
            'severity' => $this->severity,
            'title' => $critical ? 'Se va a apagar' : 'Batería baja',
            'body' => $critical
                ? "{$name} está al {$level} %. Conéctala o se apaga a media venta."
                : "{$name} está al {$level} % y no está cargando.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $name,
            'battery_level' => $level,
        ];
    }

    /**
     * El `type` que viaja por el socket. Sin esto Laravel pone el nombre de la
     * clase y el aviso en vivo no coincide con el guardado.
     */
    public function broadcastType(): string
    {
        return 'device.battery.low';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Síncrono: BroadcastNotificationCreated es ShouldBroadcast (encolado) y
        // producción no corre colas, así que sin esto el aviso no salía en vivo.
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
