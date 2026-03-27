<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = app('tenant');

        $usuarios = User::query()
            ->where('tenant_id', $tenant->id)
            ->with('roles', 'branch')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $sucursales = Branch::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Empresa/Usuarios/Index', [
            'usuarios' => $usuarios,
            'sucursales' => $sucursales,
            'filters' => $request->only('search'),
            'tenant' => $tenant,
        ]);
    }

    public function create(): Response
    {
        $sucursales = Branch::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Empresa/Usuarios/Create', [
            'sucursales' => $sucursales,
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin-sucursal,cajero',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $tenant->id,
            'branch_id' => $validated['branch_id'],
        ]);

        $user->assignRole($validated['role']);

        return redirect()->route('empresa.usuarios.index', $tenant->slug)
            ->with('success', 'Usuario creado exitosamente.');
    }

    public function edit(User $usuario): Response
    {
        $usuario->load('roles', 'branch');
        $sucursales = Branch::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Empresa/Usuarios/Edit', [
            'usuario' => $usuario,
            'sucursales' => $sucursales,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($usuario->id)],
            'password' => 'nullable|string|min:8',
            'role' => 'required|in:admin-sucursal,cajero',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $usuario->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'branch_id' => $validated['branch_id'],
            ...(filled($validated['password']) ? ['password' => Hash::make($validated['password'])] : []),
        ]);

        $usuario->syncRoles([$validated['role']]);

        return redirect()->route('empresa.usuarios.index', $tenant->slug)
            ->with('success', 'Usuario actualizado exitosamente.');
    }

    public function destroy(User $usuario): RedirectResponse
    {
        $usuario->delete();

        return redirect()->route('empresa.usuarios.index', app('tenant')->slug)
            ->with('success', 'Usuario eliminado exitosamente.');
    }
}
