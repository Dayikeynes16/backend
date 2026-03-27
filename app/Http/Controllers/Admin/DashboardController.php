<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $today = now()->toDateString();

        $tenants = Tenant::withCount('branches', 'users')
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $t) use ($today) {
                $salesToday = Sale::withoutGlobalScopes()
                    ->where('tenant_id', $t->id)
                    ->where('status', 'completed')
                    ->whereDate('completed_at', $today)
                    ->sum('total');

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'status' => $t->status,
                    'branches_count' => $t->branches_count,
                    'users_count' => $t->users_count,
                    'sales_today' => (float) $salesToday,
                ];
            });

        $globalStats = [
            'tenant_count' => Tenant::count(),
            'branch_count' => Branch::withoutGlobalScopes()->count(),
            'user_count' => User::count(),
            'sales_today' => (float) Sale::withoutGlobalScopes()
                ->where('status', 'completed')
                ->whereDate('completed_at', $today)
                ->sum('total'),
            'sale_count_today' => Sale::withoutGlobalScopes()
                ->where('status', 'completed')
                ->whereDate('completed_at', $today)
                ->count(),
        ];

        return Inertia::render('Admin/Dashboard', [
            'tenants' => $tenants,
            'stats' => $globalStats,
        ]);
    }
}
