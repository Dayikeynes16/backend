<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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

        $sucursales = Branch::where('tenant_id', app('tenant')->id)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Empresa/Usuarios/Index', [
            'usuarios' => $usuarios,
            'sucursales' => $sucursales,
            'filters' => $request->only('search'),
            'tenant' => $tenant,
            'canResetPassword' => true,
        ]);
    }

    public function create(): Response
    {
        $sucursales = Branch::where('tenant_id', app('tenant')->id)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Empresa/Usuarios/Create', [
            'sucursales' => $sucursales,
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        if ($tenant->users()->count() >= $tenant->max_users) {
            return back()->with('error', "Has alcanzado el limite de {$tenant->max_users} usuarios permitidos.");
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::defaults()],
            'role' => 'required|in:admin-sucursal,cajero',
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant->id)],
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
        $this->authorizeUserAccess($usuario);

        $usuario->load('roles', 'branch');
        $sucursales = Branch::where('tenant_id', app('tenant')->id)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Empresa/Usuarios/Edit', [
            'usuario' => $usuario,
            'sucursales' => $sucursales,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $this->authorizeUserAccess($usuario);

        $tenant = app('tenant');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($usuario->id)],
            'password' => ['nullable', Password::defaults()],
            'role' => 'required|in:admin-sucursal,cajero',
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant->id)],
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
        $this->authorizeUserAccess($usuario);

        $usuario->delete();

        return redirect()->route('empresa.usuarios.index', app('tenant')->slug)
            ->with('success', 'Usuario eliminado exitosamente.');
    }

    private function authorizeUserAccess(User $usuario): void
    {
        $tenant = app('tenant');

        if ($usuario->tenant_id !== $tenant->id) {
            abort(403, 'Este usuario no pertenece a tu empresa.');
        }

        Auth::user()->can('update', $usuario) || abort(403);
    }
}
