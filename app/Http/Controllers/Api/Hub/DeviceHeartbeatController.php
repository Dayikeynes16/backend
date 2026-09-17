<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Api\DeviceHeartbeatController as ScaleHeartbeatController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeviceHeartbeatRequest;
use App\Services\Devices\DeviceHeartbeatService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/hub/devices/heartbeat — el hub se reporta a sí mismo y reenvía
 * el latido de las básculas emparejadas que no tienen credenciales de nube.
 * La sucursal sale del usuario Sanctum; `via` es siempre `hub`.
 */
class DeviceHeartbeatController extends Controller
{
    public function store(DeviceHeartbeatRequest $request, DeviceHeartbeatService $service): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $result = $service->record((int) $user->tenant_id, (int) $user->branch_id, $request->validated(), 'hub');

        return ScaleHeartbeatController::respond($result);
    }
}
