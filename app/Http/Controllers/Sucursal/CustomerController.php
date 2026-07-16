<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

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

        return Inertia::render('Sucursal/Clientes/Index', [
            'customers' => $customers,
            'filters' => array_merge(
                $request->only('search', 'status'),
                ['sort' => $sort, 'with_debt' => $withDebt],
            ),
            'tenant' => app('tenant'),
            'customersSummary' => $this->buildCustomersSummary($branchId),
        ]);
    }

    /**
     * Resumen agregado de la cartera de clientes de la sucursal.
     * Es panorama de la cartera completa — no se filtra por search/status del listado.
     */
    private function buildCustomersSummary(int $branchId): array
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
     * Página dedicada de un cliente. Devuelve el cliente con precios
     * preferenciales y un seed de KPIs para el hero. El resto (history, top
     * products, payments) lo carga `useCustomerStats` por AJAX igual que hoy.
     */
    public function show(Customer $customer): Response
    {
        $this->authorizeBranchAccess($customer);

        $customer->load(['prices.product:id,name,price,unit_type']);

        $statsSeed = $this->buildStatsSeed($customer);

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

    /**
     * KPIs compactos para el hero de la página de detalle. Una sola query
     * agregada — la fuente canónica de los stats completos sigue siendo
     * CustomerStatsController, que el composable carga después si hace falta.
     */
    private function buildStatsSeed(Customer $customer): array
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

    private function authorizeBranchAccess(Customer $customer): void
    {
        if ($customer->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

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

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizeBranchAccess($customer);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:active,inactive',
        ]);

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

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorizeBranchAccess($customer);

        if ($customer->sales()->exists()) {
            $customer->update(['status' => 'inactive']);

            return back()->with('success', 'Cliente desactivado (tiene ventas asociadas).');
        }

        $customer->delete();

        return back()->with('success', 'Cliente eliminado.');
    }
}
