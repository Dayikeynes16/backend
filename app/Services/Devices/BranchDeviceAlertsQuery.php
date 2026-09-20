<?php

namespace App\Services\Devices;

use App\Models\Device;

/**
 * Los equipos de una sucursal que están pidiendo un cargador, en la forma más
 * corta posible: esto se pinta en una franja, no en un panel, y lo consultan
 * varias pantallas cada 60 segundos.
 *
 * El filtro va en PHP y no en SQL porque `status` es un estado derivado, no una
 * columna. Son pocos equipos por sucursal; es la misma decisión que ya tomó
 * `BranchDevicesQuery`.
 */
class BranchDeviceAlertsQuery
{
    /** @return array<int, array<string, mixed>> */
    public function forBranch(int $branchId): array
    {
        return Device::query()
            ->active()
            ->forBranch($branchId)
            ->whereNull('muted_at')
            ->with('branch')
            ->orderBy('name')
            ->get()
            ->filter(fn (Device $d) => in_array($d->status, ['battery_low', 'battery_critical'], true))
            ->map(fn (Device $d) => [
                'device_id' => $d->device_id,
                'name' => $d->displayName(),
                'battery_level' => $d->battery_level,
                'severity' => $d->status === 'battery_critical' ? 'critical' : 'warn',
            ])
            ->values()
            ->all();
    }
}
