<?php

namespace App\Exceptions\Public;

use RuntimeException;

class QuoteUnavailableException extends RuntimeException
{
    public function __construct(string $reason = 'Servicio de cotización no disponible.')
    {
        parent::__construct($reason);
    }
}
