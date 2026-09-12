<?php

namespace App\Console\Commands;

use App\Services\Devices\DeviceReleaseSync;
use Illuminate\Console\Command;

/** Cada hora: qué versión está publicada para cada tipo de equipo. */
class SyncDeviceReleasesCommand extends Command
{
    protected $signature = 'devices:sync-releases';

    protected $description = 'Lee de los feeds del bucket la última versión publicada por tipo de equipo.';

    public function handle(DeviceReleaseSync $sync): int
    {
        foreach ($sync->run() as $kind => $version) {
            $this->line(sprintf('%-14s %s', $kind, $version ?? '(sin respuesta)'));
        }

        return self::SUCCESS;
    }
}
