<?php

namespace App\Services\Devices;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Registra o actualiza un equipo a partir de su latido.
 *
 * La identidad es (tenant_id, device_id). Lo que el latido no trae no pisa lo
 * guardado; `battery: null` explícito sí limpia. Un equipo dado de baja que
 * vuelve a reportar se reactiva, y uno que reporta desde otra sucursal del
 * mismo tenant se muda de sucursal: la tablet se llevó a otro local.
 */
class DeviceHeartbeatService
{
    public function __construct(private DeviceAlertService $alerts) {}

    /**
     * @param  array<string, mixed>  $data  Payload ya validado.
     * @param  'cloud'|'hub'  $via
     */
    public function record(int $tenantId, int $branchId, array $data, string $via): HeartbeatResult
    {
        return DB::transaction(function () use ($tenantId, $branchId, $data, $via) {
            $device = Device::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('device_id', $data['device_id'])
                ->lockForUpdate()
                ->first();

            $wasNew = $device === null;
            $now = now();

            $attributes = [
                'branch_id' => $branchId,
                'kind' => $data['kind'],
                'via' => $via,
                'last_seen_at' => $now,
                'retired_at' => null,
                'silent_alerted_at' => null,
            ];

            foreach (['name', 'app_version', 'os', 'model', 'connection', 'local_ip'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if (array_key_exists('battery', $data)) {
                $attributes['battery_level'] = $data['battery']['level'] ?? null;
                $attributes['battery_charging'] = $data['battery']['charging'] ?? null;
            }

            if ($wasNew) {
                $device = Device::withoutGlobalScopes()->create(array_merge($attributes, [
                    'tenant_id' => $tenantId,
                    'device_id' => $data['device_id'],
                    'name' => $data['name'] ?? $data['device_id'],
                    'first_seen_at' => $now,
                ]));
            } else {
                $device->fill($attributes)->save();
            }

            $this->alerts->afterHeartbeat($device, $wasNew);

            return new HeartbeatResult($device->refresh(), $wasNew);
        });
    }
}
