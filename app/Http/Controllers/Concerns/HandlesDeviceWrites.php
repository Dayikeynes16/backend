<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Las tres acciones sobre un equipo (renombrar, silenciar, dar de baja), iguales
 * en Sucursal y en Empresa. Lo único que cambia es quién puede tocar qué equipo:
 * eso lo decide `authorizeDevice()` en cada controlador.
 *
 * Un equipo ya dado de baja no se toca: la fila sigue existiendo para que
 * reaparezca si vuelve a reportar, pero para la web ya no está.
 */
trait HandlesDeviceWrites
{
    abstract protected function authorizeDevice(Device $device): void;

    public function update(Request $request, Device $device): RedirectResponse
    {
        $this->guard($device);

        $validated = $request->validate(['display_name' => ['nullable', 'string', 'max:100']]);
        // '' y '   ' ya llegan como null (TrimStrings + ConvertEmptyStringsToNull);
        // no usar truthiness aquí, o el alias «0» se perdería.
        $device->update(['display_name' => $validated['display_name'] ?? null]);

        return back()->with('success', 'Nombre guardado.');
    }

    public function mute(Device $device): RedirectResponse
    {
        $this->guard($device);

        $device->update(['muted_at' => $device->isMuted() ? null : now()]);

        return back()->with('success', $device->isMuted() ? 'Avisos silenciados para este equipo.' : 'Avisos reactivados para este equipo.');
    }

    public function destroy(Device $device): RedirectResponse
    {
        $this->guard($device);

        $device->update(['retired_at' => now()]);

        return back()->with('success', 'Equipo dado de baja. Si vuelve a reportar, reaparece.');
    }

    private function guard(Device $device): void
    {
        $this->authorizeDevice($device);
        abort_if($device->isRetired(), 404);
    }
}
