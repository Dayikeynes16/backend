<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $tenant = app('tenant');
        $today = now()->toDateString();

        $salesToday = Sale::where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->get();

        $totals = [
            'total_sales' => (float) $salesToday->sum('total'),
            'sale_count' => $salesToday->count(),
            'total_cash' => (float) $salesToday->where('payment_method', 'cash')->sum('total'),
            'total_card' => (float) $salesToday->where('payment_method', 'card')->sum('total'),
            'total_transfer' => (float) $salesToday->where('payment_method', 'transfer')->sum('total'),
        ];

        $salesByBranch = Sale::select('branch_id', DB::raw('COUNT(*) as sale_count'), DB::raw('SUM(total) as total'))
            ->where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $branches = Branch::orderBy('name')->get()->map(fn (Branch $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'status' => $b->status,
            'sale_count' => (int) ($salesByBranch[$b->id]->sale_count ?? 0),
            'total' => (float) ($salesByBranch[$b->id]->total ?? 0),
        ]);

        $branchCount = Branch::count();
        $userCount = User::where('tenant_id', $tenant->id)->count();
        $pendingCount = Sale::where('status', 'pending')->count();

        return Inertia::render('Empresa/Dashboard', [
            'totals' => $totals,
            'branches' => $branches,
            'branchCount' => $branchCount,
            'userCount' => $userCount,
            'pendingCount' => $pendingCount,
            'tenant' => $tenant,
        ]);
    }
}
