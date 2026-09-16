<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\HandlesDeviceWrites;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Devices\BranchDevicesQuery;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Equipos de la sucursal del administrador: ver, renombrar, silenciar, dar de baja. */
class DeviceController extends Controller
{
    use HandlesDeviceWrites;

    public function index(BranchDevicesQuery $query): Response
    {
        $data = $query->forBranch((int) Auth::user()->branch_id);

        return Inertia::render('Sucursal/Equipos/Index', array_merge($data, [
            'tenant' => app('tenant'),
            'branchName' => Auth::user()->branch?->name,
        ]));
    }

    /** Un equipo de otra sucursal no existe para este administrador. */
    protected function authorizeDevice(Device $device): void
    {
        abort_unless($device->branch_id === Auth::user()->branch_id, 404);
    }
}
