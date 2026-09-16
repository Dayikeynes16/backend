<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Services\Devices\BranchDevicesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Todos los equipos de la empresa, agrupados por sucursal. Mismas acciones que en Sucursal. */
class DeviceController extends Controller
{
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

    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate(['display_name' => ['nullable', 'string', 'max:100']]);
        $device->update(['display_name' => trim((string) ($validated['display_name'] ?? '')) ?: null]);

        return back();
    }

    public function mute(Device $device): RedirectResponse
    {
        $device->update(['muted_at' => $device->isMuted() ? null : now()]);

        return back();
    }

    public function destroy(Device $device): RedirectResponse
    {
        $device->update(['retired_at' => now()]);

        return back();
    }
}
