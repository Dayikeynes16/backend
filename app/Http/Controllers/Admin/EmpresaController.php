<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sale;
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
            'max_sales_per_branch_month' => 'required|integer|min:1|max:10000',
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
                'max_sales_per_branch_month' => $validated['max_sales_per_branch_month'],
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

        // Sales in last 30 days per branch (for observability)
        $thirtyDaysAgo = now()->subDays(30);
        $salesByBranch = Sale::withoutGlobalScopes()
            ->where('tenant_id', $empresa->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $thirtyDaysAgo)
            ->select('branch_id', DB::raw('COUNT(*) as sale_count'))
            ->groupBy('branch_id')
            ->pluck('sale_count', 'branch_id');

        $maxSalesBranch = $salesByBranch->max() ?? 0;

        return Inertia::render('Admin/Empresas/Edit', [
            'empresa' => $empresa,
            'branches' => $branches,
            'tenantAdmins' => $tenantAdmins,
            'salesByBranch' => $salesByBranch,
            'maxSalesBranch' => $maxSalesBranch,
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
            'max_sales_per_branch_month' => 'required|integer|min:1|max:10000',
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
