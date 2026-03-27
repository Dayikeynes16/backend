<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
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

        return Inertia::render('Sucursal/Configuracion', [
            'branch' => $branch,
            'tenant' => $tenant,
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
            'payment_methods_enabled' => 'nullable|array',
            'payment_methods_enabled.*' => 'in:cash,card,transfer',
        ]);

        $branch->update($validated);

        return back()->with('success', 'Configuracion actualizada.');
    }
}
