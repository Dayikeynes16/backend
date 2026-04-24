<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfiguracionController extends Controller
{
    public function edit(): Response
    {
        $tenant = app('tenant');

        return Inertia::render('Empresa/Configuracion', [
            'tenant' => $tenant,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'owner_whatsapp' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
        ]);

        $validated['owner_whatsapp'] = ! empty($validated['owner_whatsapp'])
            ? PhoneNormalizer::normalize($validated['owner_whatsapp'])
            : null;

        $tenant->update($validated);

        return back()->with('success', 'Configuracion actualizada exitosamente.');
    }
}
