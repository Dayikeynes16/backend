<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Devices\BranchDevicesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Equipos de la sucursal del administrador: ver, renombrar, silenciar, dar de baja. */
class DeviceController extends Controller
{
    public function index(BranchDevicesQuery $query): Response
    {
        $data = $query->forBranch((int) Auth::user()->branch_id);

        return Inertia::render('Sucursal/Equipos/Index', array_merge($data, [
            'tenant' => app('tenant'),
            'branchName' => Auth::user()->branch?->name,
        ]));
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $this->own($device);
        $validated = $request->validate(['display_name' => ['nullable', 'string', 'max:100']]);
        $device->update(['display_name' => trim((string) ($validated['display_name'] ?? '')) ?: null]);

        return back()->with('success', 'Nombre guardado.');
    }

    public function mute(Device $device): RedirectResponse
    {
        $this->own($device);
        $device->update(['muted_at' => $device->isMuted() ? null : now()]);

        return back()->with('success', $device->isMuted() ? 'Avisos silenciados para este equipo.' : 'Avisos reactivados para este equipo.');
    }

    public function destroy(Device $device): RedirectResponse
    {
        $this->own($device);
        $device->update(['retired_at' => now()]);

        return back()->with('success', 'Equipo dado de baja. Si vuelve a reportar, reaparece.');
    }

    private function own(Device $device): void
    {
        abort_unless($device->branch_id === Auth::user()->branch_id, 404);
    }
}
