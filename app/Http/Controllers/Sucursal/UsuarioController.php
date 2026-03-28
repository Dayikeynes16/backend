<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
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
        $branchId = Auth::user()->branch_id;

        $usuarios = User::query()
            ->where('branch_id', $branchId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'cajero'))
            ->with('roles')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Sucursal/Usuarios/Index', [
            'usuarios' => $usuarios,
            'filters' => $request->only('search'),
            'tenant' => app('tenant'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sucursal/Usuarios/Create', [
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if ($tenant->users()->count() >= $tenant->max_users) {
            return back()->with('error', "Has alcanzado el limite de {$tenant->max_users} usuarios permitidos.");
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::defaults()],
        ]);

        $cajero = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
        ]);

        $cajero->assignRole('cajero');

        return redirect()->route('sucursal.usuarios.index', app('tenant')->slug)
            ->with('success', 'Cajero creado exitosamente.');
    }

    public function edit(User $usuario): Response
    {
        $this->authorizeBranchAccess($usuario);

        return Inertia::render('Sucursal/Usuarios/Edit', [
            'usuario' => $usuario,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $this->authorizeBranchAccess($usuario);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($usuario->id)],
            'password' => ['nullable', Password::defaults()],
        ]);

        $usuario->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            ...(filled($validated['password']) ? ['password' => Hash::make($validated['password'])] : []),
        ]);

        return redirect()->route('sucursal.usuarios.index', app('tenant')->slug)
            ->with('success', 'Cajero actualizado exitosamente.');
    }

    public function destroy(User $usuario): RedirectResponse
    {
        $this->authorizeBranchAccess($usuario);

        $usuario->delete();

        return redirect()->route('sucursal.usuarios.index', app('tenant')->slug)
            ->with('success', 'Cajero eliminado exitosamente.');
    }

    private function authorizeBranchAccess(User $usuario): void
    {
        $currentUser = Auth::user();

        if ($usuario->tenant_id !== $currentUser->tenant_id) {
            abort(403, 'Este usuario no pertenece a tu empresa.');
        }

        if ($usuario->branch_id !== $currentUser->branch_id) {
            abort(403, 'Este usuario no pertenece a tu sucursal.');
        }

        $currentUser->can('update', $usuario) || abort(403);
    }
}
