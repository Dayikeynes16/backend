<?php

namespace App\Services\Devices;

use App\Models\Device;

/** Decide qué avisos manda un equipo. Se completa en la tarea de avisos. */
class DeviceAlertService
{
    public function afterHeartbeat(Device $device, bool $wasNew): void
    {
        // Task 4.
    }
}
