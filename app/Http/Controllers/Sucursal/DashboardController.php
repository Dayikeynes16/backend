<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;
        $date = $request->date ?: now()->toDateString();

        $salesForDate = Sale::where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereDate('completed_at', $date)
            ->get();

        $totals = [
            'total_sales' => (float) $salesForDate->sum('total'),
            'sale_count' => $salesForDate->count(),
            'total_cash' => (float) $salesForDate->where('payment_method', 'cash')->sum('total'),
            'total_card' => (float) $salesForDate->where('payment_method', 'card')->sum('total'),
            'total_transfer' => (float) $salesForDate->where('payment_method', 'transfer')->sum('total'),
            'average' => $salesForDate->count() > 0 ? round((float) $salesForDate->avg('total'), 2) : 0,
        ];

        $topProducts = SaleItem::select('product_name', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(subtotal) as total_revenue'))
            ->whereHas('sale', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->where('status', 'completed')
                ->whereDate('completed_at', $date)
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

        $cancelRequestCount = Sale::where('branch_id', $branchId)
            ->whereNotNull('cancel_requested_at')
            ->whereNull('cancelled_at')
            ->where('status', '!=', 'cancelled')
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
            'cancelRequestCount' => $cancelRequestCount,
            'productCount' => $productCount,
            'cajeroCount' => $cajeroCount,
            'selectedDate' => $date,
            'tenant' => app('tenant'),
        ]);
    }
}
