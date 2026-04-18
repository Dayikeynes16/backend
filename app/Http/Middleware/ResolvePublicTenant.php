<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenantSlug');

        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();

        if (! $tenant) {
            abort(404, 'Carnicería no encontrada.');
        }

        app()->instance('tenant', $tenant);

        $request->route()->forgetParameter('tenantSlug');

        return $next($request);
    }
}
