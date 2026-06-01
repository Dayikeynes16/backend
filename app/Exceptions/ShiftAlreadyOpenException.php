<?php

namespace App\Exceptions;

use RuntimeException;

class ShiftAlreadyOpenException extends RuntimeException
{
    protected $message = 'Ya tienes un turno abierto.';
}
