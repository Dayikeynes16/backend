<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\AuthorizesCustomerAccess;
use App\Http\Controllers\Concerns\HandlesCustomers;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cartera de clientes para admin-sucursal. El listado, el seed de KPIs y las
 * altas/ediciones viven en `HandlesCustomers`, compartido con
 * `Caja\CustomerController`. Lo propio de este rol: precios preferenciales,
 * catálogo de productos para editar items desde la ficha, y baja de clientes.
 */
class CustomerController extends Controller
{
    use AuthorizesCustomerAccess;
    use HandlesCustomers;

    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        return Inertia::render('Sucursal/Clientes/Index', [
            ...$this->buildCustomersIndex($request, $branchId),
            'tenant' => app('tenant'),
            'customersSummary' => $this->buildCustomersSummary($branchId),
        ]);
    }

    /**
     * Página dedicada de un cliente. Devuelve el cliente con precios
     * preferenciales y un seed de KPIs para el hero. El resto (history, top
     * products, payments) lo carga `useCustomerStats` por AJAX igual que hoy.
     */
    public function show(Customer $customer): Response
    {
        $this->authorizeCustomerBranchAccess($customer);

        $customer->load(['prices.product:id,name,price,unit_type']);

        $statsSeed = $this->buildCustomerStatsSeed($customer);

        $branchId = Auth::user()->branch_id;
        // `status` y `presentations.status='active'` son necesarios para el
        // picker de SaleItemAddModal (filtra por status y muestra
        // presentaciones cuando el sale_mode lo requiere).
        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->with(['presentations' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'unit_type', 'sale_mode', 'status', 'branch_id']);

        $branch = Branch::withoutGlobalScopes()->find($branchId);
        $allowedMethods = $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        return Inertia::render('Sucursal/Clientes/Show', [
            'customer' => $customer,
            'statsSeed' => $statsSeed,
            'products' => $products,
            'tenant' => app('tenant'),
            'allowedPaymentMethods' => $allowedMethods,
            'saleItemEditReasonMode' => $branch?->sale_item_edit_reason_mode ?? 'optional',
            'paymentReceiptsEnabled' => (bool) ($branch?->payment_receipts_enabled || $branch?->payment_receipts_required),
            'paymentReceiptsRequired' => (bool) $branch?->payment_receipts_required,
        ]);
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorizeCustomerBranchAccess($customer);

        if ($customer->sales()->exists()) {
            $customer->update(['status' => 'inactive']);

            return back()->with('success', 'Cliente desactivado (tiene ventas asociadas).');
        }

        $customer->delete();

        return back()->with('success', 'Cliente eliminado.');
    }
}
