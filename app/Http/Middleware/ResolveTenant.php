<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::where('slug', $tenant)->where('status', 'active')->first();
        }

        if (! $tenant) {
            abort(404, 'Empresa no encontrada.');
        }

        app()->instance('tenant', $tenant);

        $request->route()->forgetParameter('tenant');

        return $next($request);
    }
}
