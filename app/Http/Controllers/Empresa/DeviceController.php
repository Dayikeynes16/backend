<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Concerns\HandlesDeviceWrites;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Services\Devices\BranchDevicesQuery;
use Inertia\Inertia;
use Inertia\Response;

/** Todos los equipos de la empresa, agrupados por sucursal. Mismas acciones que en Sucursal. */
class DeviceController extends Controller
{
    use HandlesDeviceWrites;

    public function index(BranchDevicesQuery $query): Response
    {
        $branches = Branch::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $b) => array_merge(['id' => $b->id, 'name' => $b->name], $query->forBranch($b->id)))
            ->values();

        return Inertia::render('Empresa/Equipos/Index', [
            'branches' => $branches,
            'tenant' => app('tenant'),
        ]);
    }

    /** El admin de empresa alcanza cualquier equipo del tenant; el TenantScope ya acota. */
    protected function authorizeDevice(Device $device): void {}
}
