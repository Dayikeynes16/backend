<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ConfiguracionController extends Controller
{
    public function edit(): Response
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail(Auth::user()->branch_id);
        $tenant = app('tenant');

        $apiKeys = ApiKey::where('branch_id', Auth::user()->branch_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'prefix' => substr($key->key_hash, 0, 8),
                'status' => $key->isExpired() ? 'expired' : $key->status,
                'last_used_at' => $key->last_used_at?->diffForHumans() ?? 'Nunca',
                'created_at' => $key->created_at->toDateTimeString(),
            ]);

        // Snapshot read-only de los datos administrados por el admin de
        // empresa. Incluye un schedule humano-legible derivado de hours
        // (los datos reales de horario viven en hours JSONB).
        $branchSnapshot = [
            'name' => $branch->name,
            'phone' => $branch->phone,
            'address' => $branch->address,
            'latitude' => $branch->latitude !== null ? (float) $branch->latitude : null,
            'longitude' => $branch->longitude !== null ? (float) $branch->longitude : null,
            'schedule_text' => $this->humanReadableHours($branch->hours) ?: $branch->schedule,
            'hours' => $branch->hours,
        ];

        return Inertia::render('Sucursal/Configuracion', [
            'branch' => $branch,
            'branchSnapshot' => $branchSnapshot,
            'tenant' => $tenant,
            'apiKeys' => $apiKeys,
            'newKey' => session('newKey'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail(Auth::user()->branch_id);

        // Admin-sucursal ya solo gestiona métodos de pago (operativo del POS).
        // El nombre, dirección, teléfono, ubicación y horarios los administra
        // el admin de empresa desde /empresa/sucursales/{id}/edit.
        $validated = $request->validate([
            'payment_methods_enabled' => 'required|array|min:1',
            'payment_methods_enabled.*' => 'in:cash,card,transfer',
        ]);

        $branch->update($validated);

        return back()->with('success', 'Métodos de pago actualizados.');
    }

    /**
     * Convierte hours JSONB a texto legible para humanos.
     * Ej. ['mon' => ['open'=>'07:00','close'=>'20:00'], ...] →
     *     "Lun-Sáb 7:00-20:00, Dom cerrado"
     * Devuelve null si hours está vacío.
     */
    private function humanReadableHours(?array $hours): ?string
    {
        if (empty($hours)) {
            return null;
        }

        $labels = ['mon' => 'Lun', 'tue' => 'Mar', 'wed' => 'Mié', 'thu' => 'Jue', 'fri' => 'Vie', 'sat' => 'Sáb', 'sun' => 'Dom'];
        $parts = [];
        foreach ($labels as $key => $label) {
            $day = $hours[$key] ?? null;
            if (! $day || empty($day['open']) || empty($day['close'])) {
                $parts[] = "{$label} cerrado";
            } else {
                $parts[] = "{$label} {$day['open']}-{$day['close']}";
            }
        }

        return implode(' · ', $parts);
    }
}
