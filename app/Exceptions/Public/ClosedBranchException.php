<?php

namespace App\Exceptions\Public;

use RuntimeException;

class ClosedBranchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La sucursal está cerrada en este momento.');
    }
}
