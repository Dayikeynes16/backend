<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * Quién puede gestionar clientes desde la API del hub.
 *
 * Espeja el gating de la web: el admin-sucursal siempre puede, y el cajero solo si
 * su sucursal tiene `cashier_customers_enabled` (`routes/web.php`, grupo
 * `branch.feature:cashier_customers_enabled`).
 *
 * Las tres exclusiones del cajero en la web —precios preferenciales, dar de baja un
 * cliente y cancelar un cobro— NO usan este helper: siguen exigiendo admin-sucursal
 * en su propio método, porque son decisiones que la web tampoco le da.
 *
 * Nombre largo a propósito: `AuthorizesCustomerAccess` ya existe y resuelve otra
 * cosa (que el cliente sea de tu sucursal), y lo usan tres concerns de la web.
 */
trait AuthorizesHubCustomerManagement
{
    private function ensureCanManageCustomers(Request $request): void
    {
        $user = $request->user();

        if ($user->hasRole('admin-sucursal') || $user->hasRole('superadmin')) {
            return;
        }

        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);

        abort_unless(
            $user->hasRole('cajero') && $branch && $branch->cashier_customers_enabled,
            403,
            'La gestión de clientes no está habilitada para tu sucursal.'
        );
    }

    /** El cajero edita datos de contacto, no el estado del cliente (paridad web). */
    private function canChangeCustomerStatus(Request $request): bool
    {
        $user = $request->user();

        return $user->hasRole('admin-sucursal') || $user->hasRole('superadmin');
    }
}
