<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Services\PhoneNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cartera de clientes de una sucursal: listado filtrable, resumen agregado,
 * seed de KPIs de la ficha y altas/ediciones.
 *
 * Compartido por `Sucursal\CustomerController` y `Caja\CustomerController`.
 * Lo que NO vive aquí es lo que distingue a los dos roles: la sucursal carga
 * además precios preferenciales y catálogo de productos, y es la única que
 * puede desactivar o eliminar clientes.
 *
 * Requiere `AuthorizesCustomerAccess` en el controller que lo usa.
 */
trait HandlesCustomers
{
    /**
     * Listado paginado de la cartera de una sucursal, con búsqueda por
     * nombre/teléfono, filtro de deuda y tres órdenes posibles.
     *
     * @return array{customers: LengthAwarePaginator, filters: array<string,mixed>}
     */
    protected function buildCustomersIndex(Request $request, int $branchId): array
    {
        // Sort: 'name' (default alfabético) | 'debt' (mayor deuda primero)
        // | 'last_sale' (compra más reciente primero).
        $sort = in_array($request->sort, ['name', 'debt', 'last_sale'], true) ? $request->sort : 'name';

        // Filtro especial 'with_debt' filtra a clientes con deuda actual > 0
        // (independiente del status, que sigue su propio chip Activo/Inactivo).
        $withDebt = $request->boolean('with_debt');

        $customers = Customer::where('branch_id', $branchId)
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q2) => $q2->where('name', 'ilike', "%{$s}%")
                ->orWhere('phone', 'ilike', "%{$s}%")
            ))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(! $request->status, fn ($q) => $q->where('status', 'active'))
            ->withSum([
                'sales as total_owed' => fn ($q) => $q->where('status', '!=', SaleStatus::Cancelled->value)->accountable(),
            ], 'amount_pending')
            ->withCount('prices as preferential_prices_count')
            ->withMax('sales as last_sale_at', 'created_at')
            ->withCount(['sales as sales_count' => fn ($q) => $q->where('status', '!=', SaleStatus::Cancelled->value)->accountable()])
            ->when($withDebt, fn ($q) => $q->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('sales')
                ->whereColumn('sales.customer_id', 'customers.id')
                ->where('sales.status', '!=', SaleStatus::Cancelled->value)
                ->where(fn ($w) => $w->where('sales.origin', '!=', 'web')
                    ->orWhereNotIn('sales.status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]))
                ->where('sales.amount_pending', '>', 0)
                ->whereNull('sales.deleted_at')
            ))
            ->when($sort === 'debt', fn ($q) => $q
                ->orderByRaw(
                    'COALESCE((select SUM(amount_pending) from sales where sales.customer_id = customers.id and sales.status != ? and (sales.origin != ? or sales.status not in (?, ?)) and sales.deleted_at is null), 0) DESC',
                    [
                        SaleStatus::Cancelled->value,
                        'web',
                        SaleStatus::Pending->value,
                        SaleStatus::Fulfilled->value,
                    ]
                )
                ->orderBy('name')
            )
            ->when($sort === 'last_sale', fn ($q) => $q->orderByDesc('last_sale_at')->orderBy('name'))
            ->when($sort === 'name', fn ($q) => $q->orderBy('name'))
            ->paginate(25)
            ->withQueryString();

        return [
            'customers' => $customers,
            'filters' => array_merge(
                $request->only('search', 'status'),
                ['sort' => $sort, 'with_debt' => $withDebt],
            ),
        ];
    }

    /**
     * Resumen agregado de la cartera de clientes de la sucursal.
     * Es panorama de la cartera completa — no se filtra por search/status del listado.
     *
     * @return array{total:int,active:int,inactive:int,total_debt:float,customers_with_debt:int}
     */
    protected function buildCustomersSummary(int $branchId): array
    {
        $statusCounts = Customer::where('branch_id', $branchId)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $active = (int) ($statusCounts['active'] ?? 0);
        $inactive = (int) ($statusCounts['inactive'] ?? 0);
        $total = $active + $inactive;

        // Deuda total en la sucursal — sumar amount_pending de ventas no canceladas
        // de clientes de esta sucursal.
        $debtRow = DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('customers.branch_id', $branchId)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->where(fn ($q) => $q->where('sales.origin', '!=', 'web')
                ->orWhereNotIn('sales.status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]))
            ->where('sales.amount_pending', '>', 0)
            ->whereNull('sales.deleted_at')
            ->selectRaw('
                COALESCE(SUM(sales.amount_pending), 0) as total_debt,
                COUNT(DISTINCT sales.customer_id) as customers_with_debt
            ')
            ->first();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'total_debt' => (float) ($debtRow->total_debt ?? 0),
            'customers_with_debt' => (int) ($debtRow->customers_with_debt ?? 0),
        ];
    }

    /**
     * KPIs compactos para el hero de la página de detalle. Una sola query
     * agregada — la fuente canónica de los stats completos sigue siendo
     * `HandlesCustomerStats::stats()`, que el composable carga después si hace falta.
     *
     * @return array<string,mixed>
     */
    protected function buildCustomerStatsSeed(Customer $customer): array
    {
        $row = DB::table('sales')
            ->where('customer_id', $customer->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->where(fn ($q) => $q->where('origin', '!=', 'web')
                ->orWhereNotIn('status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]))
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*)                            as sale_count,
                COALESCE(SUM(total), 0)             as total_spent,
                COALESCE(AVG(total), 0)             as avg_ticket,
                COALESCE(SUM(amount_pending), 0)    as total_owed,
                COALESCE(SUM(amount_paid), 0)       as total_paid,
                COUNT(*) FILTER (WHERE amount_pending > 0) as pending_sales_count,
                MIN(created_at)                     as first_sale_at,
                MAX(created_at)                     as last_sale_at
            ')
            ->first();

        // Ahorro acumulado y producto preferido (mismas reglas que stats):
        // ambos requieren join a sale_items y sale_items joineado.
        $savings = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.customer_id', $customer->id)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->where(fn ($q) => $q->where('sales.origin', '!=', 'web')
                ->orWhereNotIn('sales.status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]))
            ->whereNull('sales.deleted_at')
            ->selectRaw('
                COALESCE(SUM(GREATEST(sale_items.original_unit_price - sale_items.unit_price, 0) * sale_items.quantity), 0) as total_saved
            ')
            ->first();

        $topProduct = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.customer_id', $customer->id)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->where(fn ($q) => $q->where('sales.origin', '!=', 'web')
                ->orWhereNotIn('sales.status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]))
            ->whereNull('sales.deleted_at')
            ->whereNotNull('sale_items.product_id')
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByRaw('SUM(sale_items.subtotal) DESC')
            ->limit(1)
            ->selectRaw('
                sale_items.product_id,
                sale_items.product_name,
                COUNT(*) as times_bought,
                COALESCE(SUM(sale_items.subtotal), 0) as total_spent
            ')
            ->first();

        return [
            'sale_count' => (int) ($row->sale_count ?? 0),
            'total_spent' => round((float) ($row->total_spent ?? 0), 2),
            'avg_ticket' => round((float) ($row->avg_ticket ?? 0), 2),
            'total_owed' => round((float) ($row->total_owed ?? 0), 2),
            'total_paid' => round((float) ($row->total_paid ?? 0), 2),
            'total_saved' => round((float) ($savings->total_saved ?? 0), 2),
            'pending_sales_count' => (int) ($row->pending_sales_count ?? 0),
            'first_sale_at' => $row->first_sale_at,
            'last_sale_at' => $row->last_sale_at,
            'top_product' => $topProduct ? [
                'product_name' => $topProduct->product_name,
                'times_bought' => (int) $topProduct->times_bought,
                'total_spent' => round((float) $topProduct->total_spent, 2),
            ] : null,
        ];
    }

    /**
     * Alta de cliente en la sucursal del usuario. El teléfono es único por
     * sucursal — es el identificador que usa el mostrador para encontrarlo.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

        // El mutator de Customer normaliza al guardar; hay que comparar contra
        // el mismo formato o '993 123 4567' pasaría como distinto de
        // '+529931234567' y crearíamos un duplicado.
        $validated['phone'] = PhoneNormalizer::normalize($validated['phone']);

        if ($validated['phone'] === null) {
            return back()->withErrors(['phone' => 'El telefono no es valido.']);
        }

        $exists = Customer::where('branch_id', $user->branch_id)
            ->where('phone', $validated['phone'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['phone' => 'Ya existe un cliente con este telefono en esta sucursal.']);
        }

        Customer::create([
            ...$validated,
            'branch_id' => $user->branch_id,
        ]);

        return back()->with('success', 'Cliente registrado.');
    }

    /**
     * Edición de los datos de contacto. `status` solo lo acepta el rol que
     * expone el control — el cajero nunca lo envía (ver `allowsStatusChange()`).
     */
    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizeCustomerBranchAccess($customer);

        $rules = [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ];

        if ($this->allowsCustomerStatusChange()) {
            $rules['status'] = 'nullable|string|in:active,inactive';
        }

        $validated = $request->validate($rules);

        $validated['phone'] = PhoneNormalizer::normalize($validated['phone']);

        if ($validated['phone'] === null) {
            return back()->withErrors(['phone' => 'El telefono no es valido.']);
        }

        $duplicate = Customer::where('branch_id', $user->branch_id)
            ->where('phone', $validated['phone'])
            ->where('id', '!=', $customer->id)
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['phone' => 'Ya existe otro cliente con este telefono.']);
        }

        $customer->update($validated);

        return back()->with('success', 'Cliente actualizado.');
    }

    /**
     * Si el rol puede activar/desactivar clientes. El cajero no: solo edita
     * los datos de contacto.
     */
    protected function allowsCustomerStatusChange(): bool
    {
        return true;
    }
}
