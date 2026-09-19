<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Services\Devices\BranchDeviceAlertsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La misma lista para el hub, con la sucursal de su token. El hub la pinta en
 * su propia franja, con el componente que la web ya define.
 */
class DeviceAlertsController extends Controller
{
    public function index(Request $request, BranchDeviceAlertsQuery $query): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        return response()->json([
            'data' => $user->branch_id ? $query->forBranch((int) $user->branch_id) : [],
        ]);
    }
}
