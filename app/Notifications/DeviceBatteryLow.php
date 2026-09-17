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

    public function __construct(public Device $device) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $level = $this->device->battery_level;

        return [
            'type' => 'device.battery.low',
            'level' => 'important',
            'title' => 'Batería baja',
            'body' => "{$this->device->displayName()} está al {$level} % y no está cargando.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
            'battery_level' => $level,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
