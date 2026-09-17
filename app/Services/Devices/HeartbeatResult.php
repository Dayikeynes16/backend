<?php

namespace App\Services\Devices;

use App\Models\Device;

final readonly class HeartbeatResult
{
    public function __construct(public Device $device, public bool $wasNew) {}
}
