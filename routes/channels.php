<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('sucursal.{branchId}', function ($user, $branchId) {
    return $user->branch_id === (int) $branchId;
});
