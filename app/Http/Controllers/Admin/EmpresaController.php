<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    public function index(Request $request): Response
    {
        $empresas = Tenant::query()
            ->withCount('branches', 'users')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'total' => Tenant::count(),
            'active' => Tenant::where('status', 'active')->count(),
            'inactive' => Tenant::where('status', 'inactive')->count(),
        ];

        return Inertia::render('Admin/Empresas/Index', [
            'empresas' => $empresas,
            'stats' => $stats,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Empresas/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:tenants,slug|alpha_dash',
            'rfc' => 'nullable|string|max:13',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'max_branches' => 'required|integer|min:1|max:100',
            'max_users' => 'required|integer|min:1|max:500',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => ['required', Password::defaults()],
        ]);

        DB::transaction(function () use ($validated) {
            $tenant = Tenant::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'rfc' => $validated['rfc'],
                'address' => $validated['address'],
                'phone' => $validated['phone'],
                'max_branches' => $validated['max_branches'],
                'max_users' => $validated['max_users'],
            ]);

            $admin = User::create([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'tenant_id' => $tenant->id,
            ]);

            $admin->assignRole('admin-empresa');
        });

        return redirect()->route('admin.empresas.index')
            ->with('success', 'Empresa y administrador creados exitosamente.');
    }

    public function show(Tenant $empresa): Response
    {
        $empresa->loadCount('branches', 'users');

        $now = now();
        $today = $now->copy()->startOfDay();
        $last7 = $now->copy()->subDays(7)->startOfDay();
        $last30 = $now->copy()->subDays(30)->startOfDay();
        $monthStart = $now->copy()->startOfMonth();
        $prevMonthStart = $now->copy()->subMonth()->startOfMonth();
        $prevMonthEnd = $now->copy()->startOfMonth();

        $baseCompleted = fn () => Sale::withoutGlobalScopes()
            ->where('tenant_id', $empresa->id)
            ->where('status', SaleStatus::Completed);

        $sumInRange = fn ($from, $to = null) => (float) $baseCompleted()
            ->where('completed_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('completed_at', '<', $to))
            ->sum('total');

        $countInRange = fn ($from, $to = null) => $baseCompleted()
            ->where('completed_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('completed_at', '<', $to))
            ->count();

        $kpis = [
            'today' => [
                'count' => $countInRange($today),
                'revenue' => $sumInRange($today),
            ],
            'last7' => [
                'count' => $countInRange($last7),
                'revenue' => $sumInRange($last7),
            ],
            'last30' => [
                'count' => $countInRange($last30),
                'revenue' => $sumInRange($last30),
            ],
            'month' => [
                'count' => $countInRange($monthStart),
                'revenue' => $sumInRange($monthStart),
            ],
            'prev_month' => [
                'count' => $countInRange($prevMonthStart, $prevMonthEnd),
                'revenue' => $sumInRange($prevMonthStart, $prevMonthEnd),
            ],
        ];

        $kpis['avg_ticket_30d'] = $kpis['last30']['count'] > 0
            ? round($kpis['last30']['revenue'] / $kpis['last30']['count'], 2)
            : 0;

        // Daily series (last 30 days) for chart
        $dailyRows = $baseCompleted()
            ->where('completed_at', '>=', $last30)
            ->select(
                DB::raw('DATE(completed_at) as day'),
                DB::raw('COUNT(*) as sale_count'),
                DB::raw('SUM(total) as revenue')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $dailySeries = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->toDateString();
            $row = $dailyRows->get($date);
            $dailySeries[] = [
                'date' => $date,
                'count' => $row ? (int) $row->sale_count : 0,
                'revenue' => $row ? (float) $row->revenue : 0,
            ];
        }

        // Per-branch stats (last 30 days)
        $branches = $empresa->branches()
            ->withoutGlobalScopes()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(function ($branch) use ($empresa, $last30) {
                $stats = Sale::withoutGlobalScopes()
                    ->where('tenant_id', $empresa->id)
                    ->where('branch_id', $branch->id)
                    ->where('status', SaleStatus::Completed)
                    ->where('completed_at', '>=', $last30)
                    ->selectRaw('COUNT(*) as sale_count, COALESCE(SUM(total),0) as revenue')
                    ->first();

                $openShift = CashRegisterShift::withoutGlobalScopes()
                    ->where('branch_id', $branch->id)
                    ->whereNull('closed_at')
                    ->with('user:id,name')
                    ->latest('opened_at')
                    ->first();

                $lastSale = Sale::withoutGlobalScopes()
                    ->where('branch_id', $branch->id)
                    ->where('status', SaleStatus::Completed)
                    ->latest('completed_at')
                    ->value('completed_at');

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'status' => $branch->status,
                    'users_count' => $branch->users_count,
                    'sale_count_30d' => (int) $stats->sale_count,
                    'revenue_30d' => (float) $stats->revenue,
                    'open_shift' => $openShift ? [
                        'id' => $openShift->id,
                        'opened_at' => $openShift->opened_at,
                        'user_name' => $openShift->user?->name,
                    ] : null,
                    'last_sale_at' => $lastSale,
                ];
            });

        // Sales by origin (last 30 days)
        $byOrigin = $baseCompleted()
            ->where('completed_at', '>=', $last30)
            ->select('origin', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as revenue'))
            ->groupBy('origin')
            ->get()
            ->map(fn ($r) => [
                'origin' => $r->origin ?: 'manual',
                'count' => (int) $r->count,
                'revenue' => (float) $r->revenue,
            ]);

        // Top 5 products (last 30 days) by quantity sold
        $topProducts = SaleItem::withoutGlobalScopes()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $empresa->id)
            ->where('sales.status', SaleStatus::Completed->value)
            ->where('sales.completed_at', '>=', $last30)
            ->select(
                'sale_items.product_name',
                DB::raw('SUM(sale_items.quantity) as total_qty'),
                DB::raw('SUM(sale_items.subtotal) as total_revenue'),
                DB::raw('COUNT(DISTINCT sale_items.sale_id) as sale_count')
            )
            ->groupBy('sale_items.product_name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->product_name,
                'qty' => (float) $r->total_qty,
                'revenue' => (float) $r->total_revenue,
                'sale_count' => (int) $r->sale_count,
            ]);

        // Payment method distribution (last 30 days)
        $byPaymentMethod = $baseCompleted()
            ->where('completed_at', '>=', $last30)
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as revenue'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->payment_method,
                'count' => (int) $r->count,
                'revenue' => (float) $r->revenue,
            ]);

        return Inertia::render('Admin/Empresas/Show', [
            'empresa' => $empresa,
            'kpis' => $kpis,
            'dailySeries' => $dailySeries,
            'branches' => $branches,
            'byOrigin' => $byOrigin,
            'topProducts' => $topProducts,
            'byPaymentMethod' => $byPaymentMethod,
        ]);
    }

    public function edit(Tenant $empresa): Response
    {
        $empresa->loadCount('branches', 'users');

        // Load hierarchical structure: branches with their users and roles
        $branches = $empresa->branches()
            ->withoutGlobalScopes()
            ->with(['users' => fn ($q) => $q->with('roles')->orderBy('name')])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        // Users without branch (admin-empresa level)
        $tenantAdmins = User::where('tenant_id', $empresa->id)
            ->whereNull('branch_id')
            ->with('roles')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Empresas/Edit', [
            'empresa' => $empresa,
            'branches' => $branches,
            'tenantAdmins' => $tenantAdmins,
        ]);
    }

    public function update(Request $request, Tenant $empresa): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('tenants', 'slug')->ignore($empresa->id)],
            'rfc' => 'nullable|string|max:13',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'max_branches' => 'required|integer|min:1|max:100',
            'max_users' => 'required|integer|min:1|max:500',
            'status' => 'required|in:active,inactive',
        ]);

        $empresa->update($validated);

        return redirect()->route('admin.empresas.index')
            ->with('success', 'Empresa actualizada exitosamente.');
    }

    public function destroy(Tenant $empresa): RedirectResponse
    {
        $empresa->delete();

        return redirect()->route('admin.empresas.index')
            ->with('success', 'Empresa eliminada exitosamente.');
    }
}
