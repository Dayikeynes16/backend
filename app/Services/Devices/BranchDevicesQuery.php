<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Models\Sale;

/**
 * Todo lo que el panel de una sucursal necesita, ya en forma de arrays.
 *
 * "Sin registro" son básculas viejas que no mandan latido: se deducen de los
 * `origin_name` de las ventas de 30 días que no coinciden con ningún equipo
 * registrado. Es una deducción, no una identidad.
 */
class BranchDevicesQuery
{
    /** @return array{devices: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>, unregistered: array<int, array<string, mixed>>} */
    public function forBranch(int $branchId): array
    {
        $releases = DeviceRelease::all()->keyBy('kind');

        $lastSales = Sale::query()
            ->accountable()
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('origin_name')
            ->selectRaw('origin_name, max(created_at) as last_sale_at')
            ->groupBy('origin_name')
            ->pluck('last_sale_at', 'origin_name');

        $devices = Device::query()
            ->active()
            ->forBranch($branchId)
            ->orderBy('name')
            ->get()
            ->map(fn (Device $d) => $this->present($d, $releases->get($d->kind), $lastSales->get($d->name)))
            ->values();

        $alerts = $devices->filter(fn ($d) => in_array($d['status'], ['battery_low', 'silent'], true) || $d['outdated'])->values();

        $registeredNames = $devices->pluck('name')->all();
        $unregistered = $lastSales
            ->reject(fn ($at, $name) => in_array($name, $registeredNames, true))
            ->map(fn ($at, $name) => ['name' => $name, 'last_sale_at' => (string) $at])
            ->values();

        return ['devices' => $devices->all(), 'alerts' => $alerts->all(), 'unregistered' => $unregistered->all()];
    }

    /** @return array<string, mixed> */
    public function present(Device $d, ?DeviceRelease $release, ?string $lastSaleAt): array
    {
        $outdated = $release !== null && $d->app_version !== null
            && version_compare($d->app_version, $release->version, '<');

        return [
            'id' => $d->id,
            'device_id' => $d->device_id,
            'kind' => $d->kind,
            'kind_label' => $d->kindLabel(),
            'name' => $d->name,
            'display_name' => $d->display_name,
            'shown_name' => $d->displayName(),
            'app_version' => $d->app_version,
            'os' => $d->os,
            'model' => $d->model,
            'battery_level' => $d->battery_level,
            'battery_charging' => $d->battery_charging,
            'connection' => $d->connection,
            'via' => $d->via,
            'local_ip' => $d->local_ip,
            'status' => $d->status,
            'last_seen_at' => $d->last_seen_at->toIso8601String(),
            'first_seen_at' => $d->first_seen_at->toIso8601String(),
            'last_sale_at' => $lastSaleAt,
            'muted' => $d->isMuted(),
            'outdated' => $outdated,
            'release_version' => $release?->version,
        ];
    }
}
