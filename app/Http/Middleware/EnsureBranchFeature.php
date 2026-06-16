<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatea rutas según una bandera booleana de la sucursal del usuario
 * autenticado (p. ej. `branch_admin_providers_enabled`). Pensado para que el
 * admin-empresa pueda habilitar/deshabilitar capacidades del admin-sucursal
 * sucursal por sucursal. `superadmin` pasa siempre.
 *
 * Uso: `->middleware('branch.feature:branch_admin_providers_enabled')`.
 */
class EnsureBranchFeature
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if ($user->hasRole('superadmin')) {
            return $next($request);
        }

        $branch = $user->branch_id ? Branch::find($user->branch_id) : null;

        if (! $branch || ! $branch->getAttribute($flag)) {
            abort(403, 'Tu empresa no ha habilitado esta función para tu sucursal.');
        }

        return $next($request);
    }
}
