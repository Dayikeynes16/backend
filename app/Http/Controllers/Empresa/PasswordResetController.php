<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request, User $usuario): RedirectResponse
    {
        $admin = Auth::user();
        $tenant = app('tenant');

        if ($usuario->tenant_id !== $tenant->id) {
            abort(403, 'Este usuario no pertenece a tu empresa.');
        }

        $admin->can('resetPassword', $usuario) || abort(403);

        Password::sendResetLink(['email' => $usuario->email]);

        PasswordResetLog::create([
            'tenant_id' => $tenant->id,
            'admin_id' => $admin->id,
            'target_user_id' => $usuario->id,
            'method' => 'email_link',
            'admin_role' => $admin->getRoleNames()->first(),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Enlace de reset enviado a {$usuario->email}.");
    }

    public function forceReset(Request $request, User $usuario): RedirectResponse
    {
        $admin = Auth::user();
        $tenant = app('tenant');

        if ($usuario->tenant_id !== $tenant->id) {
            abort(403, 'Este usuario no pertenece a tu empresa.');
        }

        $admin->can('resetPassword', $usuario) || abort(403);

        $validated = $request->validate([
            'password' => ['required', PasswordRule::defaults()],
        ]);

        $usuario->update([
            'password' => Hash::make($validated['password']),
            'force_password_change' => true,
        ]);

        PasswordResetLog::create([
            'tenant_id' => $tenant->id,
            'admin_id' => $admin->id,
            'target_user_id' => $usuario->id,
            'method' => 'force_reset',
            'admin_role' => $admin->getRoleNames()->first(),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Contraseña temporal asignada a {$usuario->name}. Deberá cambiarla al iniciar sesión.");
    }
}
