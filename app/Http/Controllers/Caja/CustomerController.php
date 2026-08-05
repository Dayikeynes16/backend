<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Concerns\AuthorizesCustomerAccess;
use App\Http\Controllers\Concerns\HandlesCustomers;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cartera de clientes vista desde Caja. Habilitado por sucursal con
 * `cashier_customers_enabled` (ver `EnsureBranchFeature` en las rutas).
 *
 * Comparte listado, seed de KPIs y altas/ediciones con
 * `Sucursal\CustomerController` vía `HandlesCustomers`. Tres diferencias
 * deliberadas frente al admin de sucursal:
 *
 *  - **No carga precios preferenciales**: los descuentos por cliente son una
 *    decisión comercial del admin-sucursal y no se exponen aquí.
 *  - **No carga el catálogo de productos**: el cajero no edita items de venta
 *    desde la ficha, igual que en la Mesa de Trabajo.
 *  - **No hay `destroy` ni cambio de status**: dar de baja un cliente sigue
 *    siendo del admin-sucursal.
 */
class CustomerController extends Controller
{
    use AuthorizesCustomerAccess;
    use HandlesCustomers;

    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        return Inertia::render('Caja/Clientes/Index', [
            ...$this->buildCustomersIndex($request, $branchId),
            'tenant' => app('tenant'),
            'customersSummary' => $this->buildCustomersSummary($branchId),
        ]);
    }

    public function show(Customer $customer): Response
    {
        $this->authorizeCustomerBranchAccess($customer);

        $branch = Branch::withoutGlobalScopes()->find(Auth::user()->branch_id);

        return Inertia::render('Caja/Clientes/Show', [
            'customer' => $customer,
            'statsSeed' => $this->buildCustomerStatsSeed($customer),
            'tenant' => app('tenant'),
            'allowedPaymentMethods' => $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'],
            'paymentReceiptsEnabled' => (bool) ($branch?->payment_receipts_enabled || $branch?->payment_receipts_required),
            'paymentReceiptsRequired' => (bool) $branch?->payment_receipts_required,
        ]);
    }

    /**
     * El cajero edita datos de contacto, no el estado del cliente.
     */
    protected function allowsCustomerStatusChange(): bool
    {
        return false;
    }
}
