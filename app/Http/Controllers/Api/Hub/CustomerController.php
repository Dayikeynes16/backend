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
        ]);

        $branchId = $request->user()->branch_id;
        $status = $request->input('status', 'active');
        $search = trim((string) $request->input('search', ''));

        $query = Customer::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->withCount('prices')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")));

        // Agregados de deuda/compras por cliente (un solo query, sin N+1).
        $aggregates = $this->debtAggregates($branchId);

        $customers = $query->orderBy('name')->limit(200)->get();

        $rows = $customers->map(function (Customer $c) use ($aggregates) {
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
        });

        if ($request->boolean('with_debt')) {
            $rows = $rows->filter(fn ($r) => $r['total_owed'] > 0)->values();
        }

        $sort = $request->input('sort', 'name');
        $rows = match ($sort) {
            'debt' => $rows->sortByDesc('total_owed')->values(),
            'last_sale' => $rows->sortByDesc('last_sale_at')->values(),
            default => $rows->sortBy('name', SORT_FLAG_CASE | SORT_STRING)->values(),
        };

        return response()->json([
            'data' => $rows,
            'summary' => $this->portfolioSummary($branchId, $aggregates),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
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

    /** Agregados de deuda/compras por cliente de la sucursal (1 query). */
    private function debtAggregates(int $branchId): Collection
    {
        return Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->whereNotNull('customer_id')
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
        $agg = $this->debtAggregates($c->branch_id)->get($c->id);

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
            'top_product' => $this->topProduct($customer),
        ];
    }

    private function topProduct(Customer $customer): ?array
    {
        $row = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.customer_id', $customer->id)
            ->where('s.status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('si.deleted_at')
            ->whereNull('s.deleted_at')
            ->groupBy('si.product_name')
            ->selectRaw('si.product_name, COUNT(*) AS times_bought, COALESCE(SUM(si.subtotal),0) AS total_spent')
            ->orderByDesc('times_bought')
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
