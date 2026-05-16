<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El admin envió una edición sin cambios reales (mismos valores). No es un
 * error de estado, pero tampoco vale la pena registrar un evento de
 * auditoría vacío. Los controllers mapean a HTTP 422 con el mensaje.
 */
class SaleItemEditNoOp extends RuntimeException {}
