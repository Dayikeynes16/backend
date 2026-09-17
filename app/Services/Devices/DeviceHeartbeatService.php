<?php

namespace App\Services\Devices;

use App\Models\Device;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registra o actualiza un equipo a partir de su latido.
 *
 * La identidad es (tenant_id, device_id). Lo que el latido no trae no pisa lo
 * guardado; `battery: null` explícito sí limpia. Un equipo dado de baja que
 * vuelve a reportar se reactiva, y uno que reporta desde otra sucursal del
 * mismo tenant se muda de sucursal: la tablet se llevó a otro local.
 *
 * Carrera del primer latido: un `SELECT ... FOR UPDATE` no bloquea una fila
 * que todavía no existe, así que dos primeros latidos simultáneos del mismo
 * equipo pueden llegar ambos al `create()`, y el que pierde choca contra el
 * índice único (tenant_id, device_id) con un 23505. En vez de propagar ese
 * error como un 500, se reintenta `record()` una sola vez: la segunda pasada
 * encuentra la fila que el otro proceso ya creó y actualiza en lugar de
 * crear.
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
        try {
            [$device, $wasNew] = DB::transaction(function () use ($tenantId, $branchId, $data, $via) {
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

                foreach (['app_version', 'os', 'model', 'connection', 'local_ip'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $attributes[$field] = $data[$field];
                    }
                }

                // Un name vacío o nulo no pisa el guardado: la sucursal puede
                // no mandarlo (name es sometimes/nullable), y "" no es un
                // nombre válido para nada.
                if (! empty($data['name']) && is_string($data['name'])) {
                    $attributes['name'] = $data['name'];
                }

                if (array_key_exists('battery', $data)) {
                    $attributes['battery_level'] = $data['battery']['level'] ?? null;
                    $attributes['battery_charging'] = $data['battery']['charging'] ?? null;
                }

                if ($wasNew) {
                    $device = Device::withoutGlobalScopes()->create(array_merge($attributes, [
                        'tenant_id' => $tenantId,
                        'device_id' => $data['device_id'],
                        'name' => $attributes['name'] ?? $data['device_id'],
                        'first_seen_at' => $now,
                    ]));
                } else {
                    $device->fill($attributes)->save();
                }

                return [$device->refresh(), $wasNew];
            });
        } catch (UniqueConstraintViolationException $e) {
            // El otro latido ganó la carrera del create(): reintentar una
            // vez. Ahora la fila existe y el SELECT ... FOR UPDATE sí la
            // bloquea, así que esta segunda pasada actualiza.
            return $this->record($tenantId, $branchId, $data, $via);
        }

        // Fuera de la transacción: si el aviso falla (notificación, Reverb),
        // no debe deshacer el latido ya confirmado ni impedir la respuesta.
        try {
            $this->alerts->afterHeartbeat($device, $wasNew);
        } catch (Throwable $e) {
            Log::warning('No se pudo procesar el aviso de latido de equipo.', [
                'device_id' => $device->id,
                'tenant_id' => $tenantId,
                'exception' => $e->getMessage(),
            ]);
        }

        return new HeartbeatResult($device, $wasNew);
    }
}
