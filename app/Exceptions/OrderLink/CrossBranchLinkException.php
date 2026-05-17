<?php

namespace App\Exceptions\OrderLink;

use RuntimeException;

/**
 * Intento de vincular un pedido web y una venta de báscula que pertenecen
 * a sucursales o tenants distintos. Mensaje seguro para mostrar al usuario.
 */
class CrossBranchLinkException extends RuntimeException {}
