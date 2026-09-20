<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeviceHeartbeatRequest;
use App\Services\Devices\BatteryThresholds;
use App\Services\Devices\DeviceHeartbeatService;
use App\Services\Devices\HeartbeatResult;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/devices/heartbeat — la báscula se presenta y reporta su estado.
 *
 * Endpoint nuevo y aditivo: las básculas viejas no lo llaman y nada de lo que
 * ellas usan cambia. La API key dice la sucursal; el `device_id`, cuál de sus
 * básculas es. Un hub sin sesión de persona (el Hub Android, o el Electron
 * sin cajero dentro) reenvía por aquí con su propia API key y declara
 * `via = hub`; sin ese campo, el latido llegó directo.
 */
class DeviceHeartbeatController extends Controller
{
    public function store(DeviceHeartbeatRequest $request, DeviceHeartbeatService $service): JsonResponse
    {
        $result = $service->record(
            (int) $request->input('tenant_id'),
            (int) $request->input('branch_id'),
            $request->validated(),
            $request->validated('via') ?? 'cloud',
        );

        return self::respond($result);
    }

    public static function respond(HeartbeatResult $result): JsonResponse
    {
        $device = $result->device;
        $device->loadMissing('branch');
        $thresholds = BatteryThresholds::fromBranch($device->branch);

        return response()->json([
            'data' => [
                'device_id' => $device->device_id,
                'name' => $device->name,
                'display_name' => $device->display_name,
                'status' => $device->status,
                // Para que el equipo pueda quejarse de su propia pila sin
                // preguntarle a nadie, también sin internet. Campo nuevo en una
                // respuesta: quien no lo entienda lo ignora y sigue vendiendo.
                'battery_alert' => [
                    'warn' => $thresholds->warn,
                    'critical' => $thresholds->critical,
                ],
                'server_time' => now()->toIso8601String(),
            ],
        ], $result->wasNew ? 201 : 200);
    }
}
