<?php

namespace App\Services\Customers;

use App\Models\Customer;

/**
 * Resultado de resolver un teléfono a un cliente de la sucursal.
 *
 * `wasCreated` distingue "encontré al cliente" de "acabo de darlo de alta",
 * que es lo que permite a quien llama decidir si pisar el nombre placeholder
 * (pedido web, que sí trae nombre) o dejarlo pendiente (captura en la venta).
 */
readonly class CustomerResolution
{
    public function __construct(
        public Customer $customer,
        public bool $wasCreated,
    ) {}
}
