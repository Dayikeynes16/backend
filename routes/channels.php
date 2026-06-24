<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Autoriza con el guard por defecto de la ruta: 'web' en la app Inertia,
// 'sanctum' en el hub Electron (la ruta /api/v1/hub/realtime/auth corre tras
// el middleware auth:sanctum, que fija sanctum como guard por defecto).
Broadcast::channel('sucursal.{branchId}', function ($user, $branchId) {
    return $user->branch_id === (int) $branchId;
});

Broadcast::channel('agenda.user.{userId}', function ($user, int $userId) {
    return (int) $user->id === (int) $userId;
});
