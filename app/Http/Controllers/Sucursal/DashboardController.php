<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $branchId = Auth::user()->branch_id;
        $today = now()->toDateString();

        $salesToday = Sale::where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->get();

        $totals = [
            'total_sales' => (float) $salesToday->sum('total'),
            'sale_count' => $salesToday->count(),
            'total_cash' => (float) $salesToday->where('payment_method', 'cash')->sum('total'),
            'total_card' => (float) $salesToday->where('payment_method', 'card')->sum('total'),
            'total_transfer' => (float) $salesToday->where('payment_method', 'transfer')->sum('total'),
            'average' => $salesToday->count() > 0 ? round((float) $salesToday->avg('total'), 2) : 0,
        ];

        $topProducts = SaleItem::select('product_name', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(subtotal) as total_revenue'))
            ->whereHas('sale', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->where('status', 'completed')
                ->whereDate('completed_at', $today)
            )
            ->groupBy('product_name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        $recentShifts = CashRegisterShift::where('branch_id', $branchId)
            ->whereNotNull('closed_at')
            ->with('user:id,name')
            ->orderByDesc('closed_at')
            ->limit(5)
            ->get();

        $pendingCount = Sale::where('branch_id', $branchId)
            ->where('status', 'pending')
            ->count();

        $productCount = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->count();

        $cajeroCount = User::where('branch_id', $branchId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'cajero'))
            ->count();

        return Inertia::render('Sucursal/Dashboard', [
            'totals' => $totals,
            'topProducts' => $topProducts,
            'recentShifts' => $recentShifts,
            'pendingCount' => $pendingCount,
            'productCount' => $productCount,
            'cajeroCount' => $cajeroCount,
            'tenant' => app('tenant'),
        ]);
    }
}
