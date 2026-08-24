<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de avisos del usuario.
 *
 * Sólo lee y marca lo propio: `$request->user()->notifications` ya está acotado
 * por `notifiable_id`, así que no hay forma de pedir las de otro. Por eso las
 * rutas viven fuera del prefijo de tenant — no hay nada que aislar más allá del
 * propio usuario, y la campana tiene que funcionar igual en los cuatro paneles.
 */
class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
                ...$n->data,
            ]);

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $found = $request->user()->notifications()->find($notification);

        if (! $found) {
            return response()->json(['message' => 'Aviso no encontrado.'], 404);
        }

        $found->markAsRead();

        return response()->json([
            'ok' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true, 'unread_count' => 0]);
    }
}
