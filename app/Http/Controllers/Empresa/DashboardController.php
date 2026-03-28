<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $today = now()->toDateString();
        $branchFilter = $request->integer('branch_id') ?: null;

        // Explicit tenant_id filters as defense-in-depth (TenantScope also applies)
        $salesQuery = Sale::where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter));

        $salesToday = $salesQuery->get();

        $totals = [
            'total_sales' => (float) $salesToday->sum('total'),
            'sale_count' => $salesToday->count(),
            'total_cash' => (float) $salesToday->where('payment_method', 'cash')->sum('total'),
            'total_card' => (float) $salesToday->where('payment_method', 'card')->sum('total'),
            'total_transfer' => (float) $salesToday->where('payment_method', 'transfer')->sum('total'),
        ];

        $salesByBranch = Sale::select('branch_id', DB::raw('COUNT(*) as sale_count'), DB::raw('SUM(total) as total'))
            ->where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $branches = Branch::where('tenant_id', $tenant->id)->orderBy('name')->get()->map(fn (Branch $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'status' => $b->status,
            'sale_count' => (int) ($salesByBranch[$b->id]->sale_count ?? 0),
            'total' => (float) ($salesByBranch[$b->id]->total ?? 0),
        ]);

        $branchCount = Branch::where('tenant_id', $tenant->id)->count();
        $userCount = User::where('tenant_id', $tenant->id)->count();
        $pendingCount = Sale::where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
            ->count();

        return Inertia::render('Empresa/Dashboard', [
            'totals' => $totals,
            'branches' => $branches,
            'branchCount' => $branchCount,
            'userCount' => $userCount,
            'pendingCount' => $pendingCount,
            'tenant' => $tenant,
            'selectedBranch' => $branchFilter,
        ]);
    }
}
