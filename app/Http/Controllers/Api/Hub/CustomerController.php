<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestión de clientes (admin-sucursal) y apoyo de caja: listado con KPIs de
 * cartera y deuda, alta/edición/desactivación, y detalle con estadísticas e
 * historial de compras. El cobro global (fiado) y los precios preferenciales
 * viven en sus propios controladores. La deuda usa el mismo criterio que la web
 * (scopeAccountable + status != Cancelled + amount_pending > 0).
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,inactive,all',
            'with_debt' => 'nullable|boolean',
            'sort' => 'nullable|in:name,debt,last_sale',
            'page' => 'nullable|integer|min:1',
        ]);

        $branchId = $request->user()->branch_id;
        $status = $request->input('status', 'active');
        $search = trim((string) $request->input('search', ''));
        $sort = $request->input('sort', 'name');

        // Subconsultas de orden en SQL (paridad Sucursal\CustomerController):
        // con paginación real el orden en memoria dejaría fuera clientes.
        $debtSubquery = 'COALESCE((select SUM(amount_pending) from sales where sales.customer_id = customers.id and sales.status != ? and (sales.origin != ? or sales.status not in (?, ?)) and sales.deleted_at is null), 0)';
        $debtBindings = [
            SaleStatus::Cancelled->value,
            'web',
            SaleStatus::Pending->value,
            SaleStatus::Fulfilled->value,
        ];
        $lastSaleSubquery = '(select MAX(created_at) from sales where sales.customer_id = customers.id and sales.deleted_at is null)';

        $customers = Customer::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->withCount('prices')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")))
            ->when($request->boolean('with_debt'), fn ($q) => $q->whereRaw("{$debtSubquery} > 0", $debtBindings))
            ->when($sort === 'debt', fn ($q) => $q->orderByRaw("{$debtSubquery} DESC", $debtBindings)->orderBy('name'))
            ->when($sort === 'last_sale', fn ($q) => $q->orderByRaw("{$lastSaleSubquery} DESC NULLS LAST")->orderBy('name'))
            ->when($sort === 'name', fn ($q) => $q->orderBy('name'))
            ->paginate(25);

        // Agregados de deuda/compras SOLO de la página (un query, sin N+1).
        $pageIds = collect($customers->items())->pluck('id');
        $aggregates = $this->debtAggregates($branchId, $pageIds->all());

        $rows = collect($customers->items())->map(function (Customer $c) use ($aggregates) {
            $agg = $aggregates->get($c->id);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'notes' => $c->notes,
                'status' => $c->status,
                'total_owed' => $agg ? round((float) $agg->owed, 2) : 0.0,
                'sales_count' => $agg ? (int) $agg->cnt : 0,
                'last_sale_at' => $agg?->last_at,
                'preferential_prices_count' => $c->prices_count,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
            // Panorama de la cartera completa (no se filtra por search/status).
            'summary' => $this->portfolioSummary($branchId, $this->debtAggregates($branchId)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $user = $request->user();
        $validated = $this->validateCustomer($request, $user->branch_id);

        $customer = Customer::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'active',
        ]);

        return response()->json(['data' => $this->row($customer)], 201);
    }

    public function update(Request $request, int $customer): JsonResponse
    {
        $this->ensureAdmin($request);
        $found = $this->findCustomer($request, $customer);
        $validated = $this->validateCustomer($request, $request->user()->branch_id, $found->id, withStatus: true);

        $found->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'],
        ]);

        return response()->json(['data' => $this->row($found->refresh())]);
    }

    public function destroy(Request $request, int $customer): JsonResponse
    {
        $this->ensureAdmin($request);
        $found = $this->findCustomer($request, $customer);

        // Si tiene ventas, se desactiva (no se borra) para preservar el historial.
        if ($found->sales()->withoutGlobalScopes()->exists()) {
            $found->update(['status' => 'inactive']);

            return response()->json(['action' => 'deactivated']);
        }

        $found->delete();

        return response()->json(['action' => 'deleted']);
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        $found = $this->findCustomer($request, $customer);
        $found->load(['prices.product:id,name,price,unit_type']);

        return response()->json([
            'data' => $this->row($found),
            'stats' => $this->stats($found),
            'prices' => $found->prices->map(fn ($p) => [
                'id' => $p->id,
                'product_id' => $p->product_id,
                'product_name' => $p->product?->name,
                'catalog_price' => $p->product ? (float) $p->product->price : null,
                'price' => (float) $p->price,
                'unit_type' => $p->product?->unit_type,
            ])->values(),
        ]);
    }

    public function history(Request $request, int $customer): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $found = $this->findCustomer($request, $customer);

        $sales = Sale::withoutGlobalScopes()
            ->where('customer_id', $found->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->with(['items', 'payments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => HubSaleResource::collection($sales->items())->resolve($request),
            'meta' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'total' => $sales->total(),
            ],
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Paridad con la web: la gestión de clientes es exclusiva de
     * admin-sucursal (routes/web.php grupo role:admin-sucursal|superadmin).
     * El cajero solo lee (para asignar cliente a una venta en la mesa).
     */
    private function ensureAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede gestionar clientes.'
        );
    }

    /**
     * Agregados de deuda/compras por cliente de la sucursal (1 query).
     *
     * @param  list<int>|null  $customerIds  limita a esos clientes (página actual)
     */
    private function debtAggregates(int $branchId, ?array $customerIds = null): Collection
    {
        return Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->whereNotNull('customer_id')
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds))
            ->selectRaw('customer_id,
                COALESCE(SUM(CASE WHEN amount_pending > 0 THEN amount_pending ELSE 0 END), 0) AS owed,
                COUNT(*) AS cnt,
                MAX(created_at) AS last_at')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');
    }

    private function portfolioSummary(int $branchId, Collection $aggregates): array
    {
        $total = Customer::withoutGlobalScopes()->where('branch_id', $branchId)->count();
        $active = Customer::withoutGlobalScopes()->where('branch_id', $branchId)->where('status', 'active')->count();
        $withDebt = $aggregates->filter(fn ($a) => (float) $a->owed > 0);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'total_debt' => round((float) $aggregates->sum(fn ($a) => max((float) $a->owed, 0)), 2),
            'customers_with_debt' => $withDebt->count(),
        ];
    }

    private function row(Customer $c): array
    {
        $c->loadCount('prices');
        $agg = $this->debtAggregates($c->branch_id, [$c->id])->get($c->id);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'notes' => $c->notes,
            'status' => $c->status,
            'total_owed' => $agg ? round((float) $agg->owed, 2) : 0.0,
            'sales_count' => $agg ? (int) $agg->cnt : 0,
            'last_sale_at' => $agg?->last_at,
            'preferential_prices_count' => $c->prices_count,
        ];
    }

    private function stats(Customer $customer): array
    {
        $sales = Sale::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->selectRaw('
                COUNT(*) AS sale_count,
                COALESCE(SUM(total), 0) AS total_spent,
                COALESCE(SUM(amount_paid), 0) AS total_paid,
                COALESCE(SUM(CASE WHEN amount_pending > 0 THEN amount_pending ELSE 0 END), 0) AS total_owed,
                COALESCE(SUM(CASE WHEN amount_pending > 0 THEN 1 ELSE 0 END), 0) AS pending_count,
                MIN(created_at) AS first_at,
                MAX(created_at) AS last_at')
            ->first();

        $saleCount = (int) $sales->sale_count;
        $totalSpent = round((float) $sales->total_spent, 2);

        return [
            'sale_count' => $saleCount,
            'total_spent' => $totalSpent,
            'total_paid' => round((float) $sales->total_paid, 2),
            'total_owed' => round((float) $sales->total_owed, 2),
            'avg_ticket' => $saleCount > 0 ? round($totalSpent / $saleCount, 2) : 0.0,
            'pending_sales_count' => (int) $sales->pending_count,
            'first_sale_at' => $sales->first_at,
            'last_sale_at' => $sales->last_at,
            'total_saved' => $this->totalSaved($customer),
            'top_product' => $this->topProduct($customer),
        ];
    }

    /**
     * Ahorro acumulado por precios preferenciales (misma consulta que la web:
     * GREATEST(original_unit_price - unit_price, 0) * quantity en ventas
     * contables no canceladas).
     */
    private function totalSaved(Customer $customer): float
    {
        $row = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.customer_id', $customer->id)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->where(fn ($q) => $q->where('sales.origin', '!=', 'web')
                ->orWhereNotIn('sales.status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]))
            ->whereNull('sales.deleted_at')
            ->selectRaw('COALESCE(SUM(GREATEST(sale_items.original_unit_price - sale_items.unit_price, 0) * sale_items.quantity), 0) as total_saved')
            ->first();

        return round((float) ($row->total_saved ?? 0), 2);
    }

    /**
     * Mismo criterio que la web (Sucursal\CustomerController): producto con
     * mayor gasto acumulado (SUM(subtotal) DESC), excluyendo canceladas y
     * pedidos web no contables (accountable).
     */
    private function topProduct(Customer $customer): ?array
    {
        $row = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.customer_id', $customer->id)
            ->where('s.status', '!=', SaleStatus::Cancelled->value)
            ->where(fn ($q) => $q->where('s.origin', '!=', 'web')
                ->orWhereNotIn('s.status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]))
            ->whereNull('si.deleted_at')
            ->whereNull('s.deleted_at')
            ->whereNotNull('si.product_id')
            ->groupBy('si.product_id', 'si.product_name')
            ->selectRaw('si.product_name, COUNT(*) AS times_bought, COALESCE(SUM(si.subtotal),0) AS total_spent')
            ->orderByRaw('SUM(si.subtotal) DESC')
            ->first();

        return $row ? [
            'product_name' => $row->product_name,
            'times_bought' => (int) $row->times_bought,
            'total_spent' => round((float) $row->total_spent, 2),
        ] : null;
    }

    private function findCustomer(Request $request, int $customer): Customer
    {
        return Customer::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->findOrFail($customer);
    }

    /** @return array<string, mixed> */
    private function validateCustomer(Request $request, int $branchId, ?int $ignoreId = null, bool $withStatus = false): array
    {
        $phoneRule = Rule::unique('customers', 'phone')
            ->where(fn ($q) => $q->where('branch_id', $branchId));
        if ($ignoreId) {
            $phoneRule = $phoneRule->ignore($ignoreId);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'phone' => ['nullable', 'string', 'max:20', $phoneRule],
            'notes' => 'nullable|string|max:1000',
        ];
        if ($withStatus) {
            $rules['status'] = 'required|in:active,inactive';
        }

        return $request->validate($rules, [
            'phone.unique' => 'Ya existe un cliente con ese teléfono en la sucursal.',
        ]);
    }
}
