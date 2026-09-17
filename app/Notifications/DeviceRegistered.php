<?php

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Un equipo nuevo se presentó por primera vez. Persistente: si nadie estaba mirando, lo encuentra al entrar. */
class DeviceRegistered extends Notification
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
            'type' => 'device.registered',
            'level' => 'important',
            'title' => 'Equipo nuevo',
            'body' => "Un equipo nuevo reporta en {$this->device->branch->name}: {$this->device->displayName()} ({$this->device->kindLabel()}, {$this->device->app_version}).",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
