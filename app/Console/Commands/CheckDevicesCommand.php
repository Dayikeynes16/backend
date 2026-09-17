<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Services\Devices\DeviceAlertService;
use Illuminate\Console\Command;

/**
 * Cada 5 minutos: equipos callados dentro del horario y versiones atrasadas.
 * Las marcas de la fila garantizan un aviso por episodio; aquí solo se recorre.
 */
class CheckDevicesCommand extends Command
{
    protected $signature = 'devices:check';

    protected $description = 'Avisa de equipos sin reportar y de versiones atrasadas.';

    public function handle(DeviceAlertService $alerts): int
    {
        $now = now();
        $releases = DeviceRelease::all()->keyBy('kind');
        $silent = 0;
        $outdated = 0;

        Device::withoutGlobalScopes()
            ->whereNull('retired_at')
            ->with('branch')
            ->chunkById(200, function ($devices) use ($alerts, $releases, $now, &$silent, &$outdated) {
                foreach ($devices as $device) {
                    if ($alerts->checkSilence($device, $now)) {
                        $silent++;
                    }
                    $release = $releases->get($device->kind);
                    if ($release && $alerts->checkOutdated($device, $release, $now)) {
                        $outdated++;
                    }
                }
            });

        $this->line("Sin reportar: {$silent} · Versión atrasada: {$outdated}");

        return self::SUCCESS;
    }
}
