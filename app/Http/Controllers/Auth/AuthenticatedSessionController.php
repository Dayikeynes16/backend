<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended($this->redirectPath());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    protected function redirectPath(): string
    {
        $user = Auth::user();

        if ($user->hasRole('superadmin')) {
            return route('admin.dashboard');
        }

        $slug = $user->tenant?->slug;

        if (! $slug) {
            return route('dashboard');
        }

        return match (true) {
            $user->hasRole('admin-empresa') => route('empresa.dashboard', $slug),
            $user->hasRole('admin-sucursal') => route('sucursal.dashboard', $slug),
            $user->hasRole('cajero') => route('caja.workbench', $slug),
            default => route('dashboard'),
        };
    }
}
