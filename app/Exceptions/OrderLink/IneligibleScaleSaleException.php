<?php

namespace App\Exceptions\OrderLink;

use RuntimeException;

/**
 * La venta de báscula (la "venta real" que el cajero intenta vincular a un
 * pedido web) no califica: es una venta web, ya está vinculada a otro pedido,
 * o no está en estado Active. Mensaje seguro para mostrar al usuario.
 */
class IneligibleScaleSaleException extends RuntimeException {}
