<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function index(Request $request): Response
    {
        $sucursales = Branch::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->withCount('users')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Empresa/Sucursales/Index', [
            'sucursales' => $sucursales,
            'filters' => $request->only('search'),
            'tenant' => app('tenant'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Empresa/Sucursales/Create', [
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'schedule' => 'nullable|string|max:255',
        ]);

        $tenant = app('tenant');
        $validated['tenant_id'] = $tenant->id;

        Branch::create($validated);

        return redirect()->route('empresa.sucursales.index', $tenant->slug)
            ->with('success', 'Sucursal creada exitosamente.');
    }

    public function edit(Branch $sucursal): Response
    {
        return Inertia::render('Empresa/Sucursales/Edit', [
            'sucursal' => $sucursal,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, Branch $sucursal): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'schedule' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $sucursal->update($validated);

        return redirect()->route('empresa.sucursales.index', app('tenant')->slug)
            ->with('success', 'Sucursal actualizada exitosamente.');
    }

    public function destroy(Branch $sucursal): RedirectResponse
    {
        $sucursal->delete();

        return redirect()->route('empresa.sucursales.index', app('tenant')->slug)
            ->with('success', 'Sucursal eliminada exitosamente.');
    }
}
