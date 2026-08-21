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
 * El gate cubre también las **lecturas de la ficha** (detalle, historial de compras y
 * ledger de fiado): en la web viven dentro del mismo grupo `branch.feature`, así que
 * un cajero con el módulo apagado no debe alcanzarlas por la API. La única lectura
 * deliberadamente abierta es `GET customers`, porque alimenta el selector de cliente
 * de la mesa de trabajo, que la web entrega sin gate — pero en modo libreta, sin
 * cartera (ver `CustomerController::index`).
 *
 * Nombre largo a propósito: `AuthorizesCustomerAccess` ya existe y resuelve otra
 * cosa (que el cliente sea de tu sucursal), y lo usan tres concerns de la web.
 */
trait AuthorizesHubCustomerManagement
{
    /**
     * ¿Este usuario tiene el módulo de clientes? Versión sin abort, para las
     * lecturas que cambian de forma en vez de fallar.
     */
    private function canManageCustomers(Request $request): bool
    {
        $user = $request->user();

        if ($this->isBranchAdmin($request)) {
            return true;
        }

        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);

        return $user->hasRole('cajero') && $branch && (bool) $branch->cashier_customers_enabled;
    }

    private function ensureCanManageCustomers(Request $request): void
    {
        abort_unless(
            $this->canManageCustomers($request),
            403,
            'La gestión de clientes no está habilitada para tu sucursal.'
        );
    }

    /** El cajero edita datos de contacto, no el estado del cliente (paridad web). */
    private function canChangeCustomerStatus(Request $request): bool
    {
        return $this->isBranchAdmin($request);
    }

    /**
     * Los precios preferenciales son decisión comercial del admin-sucursal: la web
     * ni siquiera los carga en la ficha de caja (`Caja\CustomerController`), así que
     * la API tampoco los manda.
     */
    private function canSeePreferentialPrices(Request $request): bool
    {
        return $this->isBranchAdmin($request);
    }

    private function isBranchAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user->hasRole('admin-sucursal') || $user->hasRole('superadmin');
    }
}
