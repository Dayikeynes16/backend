<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForcePasswordChangeController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/ForcePasswordChange');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = Auth::user();

        $user->update([
            'password' => Hash::make($validated['password']),
            'force_password_change' => false,
        ]);

        // Redirect based on role
        $slug = $user->tenant?->slug;

        return match (true) {
            $user->hasRole('superadmin') => redirect()->route('admin.dashboard'),
            $user->hasRole('admin-empresa') => redirect()->route('empresa.dashboard', $slug),
            $user->hasRole('admin-sucursal') => redirect()->route('sucursal.dashboard', $slug),
            $user->hasRole('cajero') => redirect()->route('caja.queue', $slug),
            default => redirect()->route('login'),
        };
    }
}
