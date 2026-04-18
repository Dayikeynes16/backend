<?php

namespace App\Exceptions\Public;

use RuntimeException;

class OutOfRangeException extends RuntimeException
{
    public function __construct(public readonly float $distanceKm)
    {
        parent::__construct("Distancia {$distanceKm} km fuera de rango.");
    }
}
