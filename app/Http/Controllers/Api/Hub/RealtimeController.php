<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class RealtimeController extends Controller
{
    /**
     * Parámetros de conexión a Reverb para que el hub abra el WebSocket. La key
     * es pública (no el secret). Equivale a las VITE_REVERB_* de la web.
     */
    public function config(): JsonResponse
    {
        $reverb = config('broadcasting.connections.reverb');

        return response()->json([
            'key' => $reverb['key'] ?? null,
            'host' => $reverb['options']['host'] ?? null,
            'port' => (int) ($reverb['options']['port'] ?? 8080),
            'scheme' => $reverb['options']['scheme'] ?? 'http',
        ]);
    }

    /**
     * Autoriza la suscripción a un canal privado para el hub. Igual que la ruta
     * /broadcasting/auth de la web, pero vía Sanctum (token Bearer del login, sin
     * sesión/CSRF). El canal sucursal.{branchId} incluye el guard 'sanctum'.
     */
    public function authenticate(Request $request): mixed
    {
        return Broadcast::auth($request);
    }
}
