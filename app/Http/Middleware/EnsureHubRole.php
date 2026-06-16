<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHubRole
{
    /** @var list<string> */
    private const HUB_ROLES = ['cajero', 'admin-sucursal'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(self::HUB_ROLES)) {
            return response()->json(['message' => 'No tienes permiso para usar el hub.'], 403);
        }

        return $next($request);
    }
}
