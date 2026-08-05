<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Concerns\AuthorizesCustomerAccess;
use App\Http\Controllers\Concerns\HandlesCustomerGlobalPayments;
use App\Http\Controllers\Controller;

/**
 * Cobro global del cliente (distribución FIFO) desde Caja. Habilitado por
 * sucursal con `cashier_customers_enabled`.
 *
 * Solo registro y consulta: la **cancelación** de un cobro no se expone al
 * cajero — no existe ruta `destroy` en este grupo y el método de
 * `Sucursal\CustomerPaymentController` ya rechaza a quien no sea
 * admin-sucursal o superior.
 */
class CustomerPaymentController extends Controller
{
    use AuthorizesCustomerAccess;
    use HandlesCustomerGlobalPayments;
}
