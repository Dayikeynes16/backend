<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = app('tenant');

        if (! $user || ! $tenant) {
            abort(403);
        }

        if ($user->hasRole('superadmin')) {
            return $next($request);
        }

        if ($user->tenant_id !== $tenant->id) {
            abort(403, 'No tienes acceso a esta empresa.');
        }

        return $next($request);
    }
}
