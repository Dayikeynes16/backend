<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Concerns\AuthorizesCustomerAccess;
use App\Http\Controllers\Concerns\HandlesCustomerStats;
use App\Http\Controllers\Controller;

/**
 * Endpoints JSON de la ficha de cliente para el cajero. Son de solo lectura y
 * ya filtran por la sucursal del usuario, así que son idénticos a los del
 * admin-sucursal: toda la lógica vive en `HandlesCustomerStats`.
 */
class CustomerStatsController extends Controller
{
    use AuthorizesCustomerAccess;
    use HandlesCustomerStats;
}
