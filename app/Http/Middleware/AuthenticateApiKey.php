<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $request->header('X-Api-Key');

        if (! $rawKey) {
            return response()->json([
                'message' => 'API Key requerida. Envía el header X-Api-Key.',
            ], 401);
        }

        $hash = hash('sha256', $rawKey);

        $apiKey = ApiKey::withoutGlobalScopes()
            ->where('key_hash', $hash)
            ->with('branch.tenant')
            ->first();

        if (! $apiKey || $apiKey->status !== 'active') {
            return response()->json([
                'message' => 'API Key inválida o inactiva.',
            ], 401);
        }

        if ($apiKey->isExpired()) {
            return response()->json([
                'message' => 'API Key expirada. Genera una nueva desde el panel de administración.',
            ], 401);
        }

        $branch = $apiKey->branch;
        $tenant = $branch->tenant;

        if ($branch->status !== 'active' || $tenant->status !== 'active') {
            return response()->json([
                'message' => 'La sucursal o empresa asociada está inactiva.',
            ], 401);
        }

        // Rate limiting: 60 requests per minute per API Key
        $rateLimitKey = 'api-key:' . $apiKey->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 60)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message' => 'Rate limit excedido. Intenta de nuevo en ' . $retryAfter . ' segundos.',
            ], 429)->header('Retry-After', $retryAfter);
        }

        RateLimiter::hit($rateLimitKey, 60);

        // Inject context for controllers
        app()->instance('tenant', $tenant);
        $request->merge([
            'api_key_id' => $apiKey->id,
            'branch_id' => $branch->id,
            'tenant_id' => $tenant->id,
        ]);
        $request->setUserResolver(fn () => null);

        // Update last_used_at
        $apiKey->updateQuietly(['last_used_at' => now()]);

        return $next($request);
    }
}
