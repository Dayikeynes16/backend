<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    private array $except = [
        'password.force-change',
        'password.force-change.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->force_password_change) {
            $currentRoute = $request->route()?->getName();

            if (! in_array($currentRoute, $this->except)) {
                return redirect()->route('password.force-change');
            }
        }

        return $next($request);
    }
}
