<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    public function index(Request $request): Response
    {
        $empresas = Tenant::query()
            ->withCount('branches', 'users')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => Tenant::count(),
            'active' => Tenant::where('status', 'active')->count(),
            'inactive' => Tenant::where('status', 'inactive')->count(),
        ];

        return Inertia::render('Admin/Empresas/Index', [
            'empresas' => $empresas,
            'stats' => $stats,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Empresas/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:tenants,slug|alpha_dash',
            'rfc' => 'nullable|string|max:13',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'max_branches' => 'required|integer|min:1|max:100',
        ]);

        Tenant::create($validated);

        return redirect()->route('admin.empresas.index')
            ->with('success', 'Empresa creada exitosamente.');
    }

    public function edit(Tenant $empresa): Response
    {
        $empresa->loadCount('branches', 'users');

        return Inertia::render('Admin/Empresas/Edit', [
            'empresa' => $empresa,
        ]);
    }

    public function update(Request $request, Tenant $empresa): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('tenants', 'slug')->ignore($empresa->id)],
            'rfc' => 'nullable|string|max:13',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'max_branches' => 'required|integer|min:1|max:100',
            'status' => 'required|in:active,inactive',
        ]);

        $empresa->update($validated);

        return redirect()->route('admin.empresas.index')
            ->with('success', 'Empresa actualizada exitosamente.');
    }

    public function destroy(Tenant $empresa): RedirectResponse
    {
        $empresa->delete();

        return redirect()->route('admin.empresas.index')
            ->with('success', 'Empresa eliminada exitosamente.');
    }
}
