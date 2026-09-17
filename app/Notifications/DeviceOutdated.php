<?php

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Un equipo sigue en una versión vieja un día después de publicada la nueva. Persistente: si nadie estaba mirando, lo encuentra al entrar. */
class DeviceOutdated extends Notification
{
    use Queueable;

    public function __construct(public Device $device, public string $latest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'device.outdated',
            'level' => 'important',
            'title' => 'Versión atrasada',
            'body' => "{$this->device->displayName()} sigue en {$this->device->app_version}; la {$this->latest} lleva un día publicada.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
            'app_version' => $this->device->app_version,
            'latest_version' => $this->latest,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
