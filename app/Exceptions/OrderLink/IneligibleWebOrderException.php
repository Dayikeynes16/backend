<?php

namespace App\Exceptions\OrderLink;

use RuntimeException;

/**
 * El pedido web seleccionado para el emparejamiento no califica: no es
 * origin='web', ya está cumplido/cancelado, o no está en estado Pending.
 * Mensaje seguro para mostrar al usuario.
 */
class IneligibleWebOrderException extends RuntimeException {}
