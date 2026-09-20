<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Todo lo que el panel de una sucursal necesita, ya en forma de arrays.
 *
 * "Sin registro" son básculas viejas que no mandan latido: se deducen de los
 * `origin_name` de las ventas de 30 días **hechas por la Scale API** (`origin =
 * 'api'`) que no coinciden con ningún equipo conocido, ni activo ni dado de
 * baja. Es una deducción, no una identidad. Las ventas del mostrador y del hub
 * escriben `origin_name = 'Administrador'` y no son básculas.
 */
class BranchDevicesQuery
{
    /** @var Collection<string, DeviceRelease>|null */
    private ?Collection $releases = null;

    /** @return array{devices: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>, unregistered: array<int, array<string, mixed>>} */
    public function forBranch(int $branchId): array
    {
        $releases = $this->releases();

        // Claves como string: un nombre numérico («2») se volvería int al hacer
        // pluck y dejaría de coincidir con el nombre del equipo.
        $lastSales = Sale::query()
            ->where('branch_id', $branchId)
            ->where('origin', 'api')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('origin_name')
            ->selectRaw('origin_name, max(created_at) as last_sale_at')
            ->groupBy('origin_name')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->origin_name => Carbon::parse($row->last_sale_at)->toIso8601String()]);

        // `with('branch')`: el estado de cada equipo se compara contra los
        // umbrales de su sucursal, y el panel de Empresa presenta N sucursales
        // de una vez. Sin esto, una consulta por equipo.
        $devices = Device::query()
            ->active()
            ->forBranch($branchId)
            ->with('branch')
            ->orderBy('name')
            ->get()
            ->map(fn (Device $d) => $this->present($d, $releases->get($d->kind), $lastSales->get((string) $d->name)))
            ->values();

        $alerts = $devices->filter(fn ($d) => in_array($d['status'], ['battery_low', 'battery_critical', 'silent'], true) || $d['outdated'])->values();

        // También los dados de baja: si uno vendió ayer y hoy se retira, no debe
        // volver a aparecer abajo como si fuera una báscula vieja distinta.
        $knownNames = Device::query()->forBranch($branchId)->pluck('name')->map(fn ($n) => (string) $n)->all();
        $unregistered = $lastSales
            ->reject(fn ($at, $name) => in_array((string) $name, $knownNames, true))
            ->map(fn ($at, $name) => ['name' => (string) $name, 'last_sale_at' => $at])
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
            // La calcula la consulta, no el componente: la consumen tres paneles
            // (Sucursal, Empresa y Caja) y si cada uno la dedujera, la deducirían
            // distinto. Sale de `status`, no de `severityFor()` directo: un
            // equipo `silent` con una lectura vieja de batería baja no está en
            // alerta de batería —lo mismo que ya hace `BranchDeviceAlertsQuery`—,
            // así que aquí no tiene severidad.
            'severity' => match ($d->status) {
                'battery_critical' => 'critical',
                'battery_low' => 'warn',
                default => null,
            },
            'last_seen_at' => $d->last_seen_at->toIso8601String(),
            'first_seen_at' => $d->first_seen_at->toIso8601String(),
            'last_sale_at' => $lastSaleAt,
            'muted' => $d->isMuted(),
            'outdated' => $outdated,
            'release_version' => $release?->version,
        ];
    }

    /** Una sola lectura por petición aunque Empresa pregunte por N sucursales. */
    private function releases(): Collection
    {
        return $this->releases ??= DeviceRelease::all()->keyBy('kind');
    }
}
