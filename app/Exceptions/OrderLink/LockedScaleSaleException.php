<?php

namespace App\Exceptions\OrderLink;

use RuntimeException;

/**
 * No se puede desvincular el pedido porque la venta de báscula ya no es
 * editable: está fuera de estado Active o ya tiene pagos registrados.
 * Mensaje seguro para mostrar al usuario.
 */
class LockedScaleSaleException extends RuntimeException {}
