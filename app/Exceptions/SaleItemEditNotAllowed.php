<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El estado actual de la venta o las condiciones de la edición no permiten
 * la mutación solicitada. Los controllers mapean a HTTP 422 con el mensaje
 * (es seguro mostrarlo al usuario; siempre se redacta para esa audiencia).
 */
class SaleItemEditNotAllowed extends RuntimeException {}
