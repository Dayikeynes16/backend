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

        return Inertia::render('Sucursal/Configuracion', [
            'branch' => $branch,
            'tenant' => $tenant,
            'apiKeys' => $apiKeys,
            'newKey' => session('newKey'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail(Auth::user()->branch_id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'schedule' => 'nullable|string|max:255',
            'payment_methods_enabled' => 'required|array|min:1',
            'payment_methods_enabled.*' => 'in:cash,card,transfer',
        ]);

        $branch->update($validated);

        return back()->with('success', 'Configuracion actualizada.');
    }
}
