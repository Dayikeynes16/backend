<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Services\Devices\BranchDevicesQuery;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipos de la sucursal del cajero, **solo para ver**.
 *
 * Quien está en el mostrador es quien primero nota una báscula sin batería o
 * apagada, así que ve el mismo tablero que su administrador. Renombrar,
 * silenciar y dar de baja siguen siendo del administrador: aquí no hay rutas
 * de escritura, y los avisos de la campana tampoco le llegan.
 */
class DeviceController extends Controller
{
    public function index(BranchDevicesQuery $query): Response
    {
        $data = $query->forBranch((int) Auth::user()->branch_id);

        return Inertia::render('Caja/Equipos/Index', array_merge($data, [
            'tenant' => app('tenant'),
            'branchName' => Auth::user()->branch?->name,
        ]));
    }
}
