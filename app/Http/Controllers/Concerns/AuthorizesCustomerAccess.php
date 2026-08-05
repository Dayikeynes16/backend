<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;

/**
 * Guarda de sucursal para las superficies de Clientes (admin-sucursal y cajero).
 * Ambos roles operan exclusivamente sobre la cartera de su propia sucursal.
 *
 * Vive en su propio trait porque `HandlesCustomers`, `HandlesCustomerStats` y
 * `HandlesCustomerGlobalPayments` lo necesitan: si cada uno lo definiera, un
 * controller que use dos de ellos chocaría por método duplicado.
 */
trait AuthorizesCustomerAccess
{
    protected function authorizeCustomerBranchAccess(Customer $customer): void
    {
        if ($customer->branch_id !== Auth::user()->branch_id) {
            abort(403, 'Cliente fuera de tu sucursal.');
        }
    }
}
