<?php

namespace App\Http\Controllers;

use App\Services\Devices\BranchDeviceAlertsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que la franja pregunta cada 60 segundos, en la caja y en la sucursal.
 *
 * Un usuario sin sucursal —el superadmin— recibe la lista vacía y no un 403:
 * la franja vive en el layout y estaría disparando un error contra una pantalla
 * que él sí puede ver.
 */
class DeviceAlertsController extends Controller
{
    public function index(Request $request, BranchDeviceAlertsQuery $query): JsonResponse
    {
        $branchId = $request->user()?->branch_id;

        return response()->json([
            'data' => $branchId ? $query->forBranch((int) $branchId) : [],
        ]);
    }
}
