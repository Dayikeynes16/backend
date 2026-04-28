<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $tz = config('app.timezone');
        $date = $request->date ?: now($tz)->toDateString();
        $dateObj = CarbonImmutable::parse($date, $tz);
        $yesterday = $dateObj->subDay()->toDateString();

        $salesForDate = Sale::where('branch_id', $branchId)
            ->where('status', SaleStatus::Completed)
            ->whereDate('completed_at', $date)
            ->get();

        $salesYesterday = Sale::where('branch_id', $branchId)
            ->where('status', SaleStatus::Completed)
            ->whereDate('completed_at', $yesterday)
            ->get();

        $totalsYesterday = (float) $salesYesterday->sum('total');
        $totalsToday = (float) $salesForDate->sum('total');
        $deltaPct = $totalsYesterday > 0
            ? round((($totalsToday - $totalsYesterday) / $totalsYesterday) * 100, 1)
            : null;

        $totals = [
            'total_sales' => $totalsToday,
            'total_sales_yesterday' => $totalsYesterday,
            'delta_pct' => $deltaPct,
            'sale_count' => $salesForDate->count(),
            'sale_count_yesterday' => $salesYesterday->count(),
            'total_cash' => (float) $salesForDate->where('payment_method', 'cash')->sum('total'),
            'total_card' => (float) $salesForDate->where('payment_method', 'card')->sum('total'),
            'total_transfer' => (float) $salesForDate->where('payment_method', 'transfer')->sum('total'),
            'average' => $salesForDate->count() > 0 ? round((float) $salesForDate->avg('total'), 2) : 0,
        ];

        // Ventas por hora (hoy y ayer) — rango 7h a 19h (13 horas = rango operativo típico).
        $hoursData = $this->hourlyBreakdown($branchId, $date);
        $yesterdayHoursData = $this->hourlyBreakdown($branchId, $yesterday);

        // Desglose de métodos de pago (dinámico, no hardcoded).
        $paymentMethods = $this->paymentMethodsBreakdown($branchId, $date);

        $topProducts = SaleItem::select('product_name', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(subtotal) as total_revenue'))
            ->whereHas('sale', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->where('status', SaleStatus::Completed)
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
            ->where('status', SaleStatus::Pending)
            ->count();

        $cancelRequestCount = Sale::where('branch_id', $branchId)
            ->whereNotNull('cancel_requested_at')
            ->whereNull('cancelled_at')
            ->where('status', '!=', SaleStatus::Cancelled)
            ->count();

        $productCount = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->count();

        $cajeroCount = User::where('branch_id', $branchId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'cajero'))
            ->count();

        $activeCashierCount = CashRegisterShift::where('branch_id', $branchId)
            ->whereNull('closed_at')
            ->whereDate('opened_at', '<=', $date)
            ->count();

        $expenses = $this->expensesSnapshot(['branch_id' => $branchId], $date, $yesterday);

        return Inertia::render('Sucursal/Dashboard', [
            'totals' => $totals,
            'hoursData' => $hoursData,
            'yesterdayHoursData' => $yesterdayHoursData,
            'paymentMethods' => $paymentMethods,
            'topProducts' => $topProducts,
            'recentShifts' => $recentShifts,
            'pendingCount' => $pendingCount,
            'cancelRequestCount' => $cancelRequestCount,
            'productCount' => $productCount,
            'cajeroCount' => $cajeroCount,
            'activeCashierCount' => $activeCashierCount,
            'selectedDate' => $date,
            'expenses' => $expenses,
            'tenant' => app('tenant'),
        ]);
    }

    /**
     * Snapshot del bloque de gastos para el dashboard. Acepta un filtro
     * arbitrario (branch_id o tenant_id) para reutilizarse desde Empresa.
     */
    private function expensesSnapshot(array $filter, string $date, string $yesterday): array
    {
        $applyFilter = function ($query) use ($filter) {
            foreach ($filter as $col => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $query->where($col, $val);
            }

            return $query;
        };

        $totalToday = (float) $applyFilter(Expense::query())->whereDate('expense_at', $date)->sum('amount');
        $totalYesterday = (float) $applyFilter(Expense::query())->whereDate('expense_at', $yesterday)->sum('amount');
        $countToday = (int) $applyFilter(Expense::query())->whereDate('expense_at', $date)->count();

        $deltaPct = $totalYesterday > 0
            ? round((($totalToday - $totalYesterday) / $totalYesterday) * 100, 1)
            : null;

        // Sparkline: gastos por hora (mismo rango 7-19 que ventas)
        $perHour = DB::table('expenses')
            ->where(function ($q) use ($filter) {
                foreach ($filter as $col => $val) {
                    if ($val === null || $val === '') {
                        continue;
                    }
                    $q->where($col, $val);
                }
            })
            ->whereNull('deleted_at')
            ->whereDate('expense_at', $date)
            ->selectRaw('EXTRACT(HOUR FROM expense_at) as hour, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('hour')
            ->get()
            ->keyBy(fn ($r) => (int) $r->hour);

        $hourly = [];
        for ($h = 7; $h <= 19; $h++) {
            $row = $perHour->get($h);
            $hourly[] = [
                'h' => (string) $h,
                'amount' => $row ? (float) $row->amount : 0.0,
            ];
        }

        // Top subcategorías del día
        $topCategories = $applyFilter(Expense::query())
            ->whereDate('expense_at', $date)
            ->select('expense_subcategory_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('expense_subcategory_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('subcategory:id,expense_category_id,name', 'subcategory.category:id,name')
            ->get()
            ->map(fn ($r) => [
                'subcategory' => $r->subcategory?->name,
                'category' => $r->subcategory?->category?->name,
                'total' => (float) $r->total,
                'count' => (int) $r->count,
            ])
            ->values()
            ->all();

        $recent = $applyFilter(Expense::query())
            ->whereDate('expense_at', $date)
            ->with(['subcategory:id,name,expense_category_id', 'subcategory.category:id,name', 'branch:id,name', 'user:id,name'])
            ->orderByDesc('expense_at')
            ->limit(5)
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'concept' => $e->concept,
                'amount' => (float) $e->amount,
                'expense_at' => $e->expense_at,
                'category' => $e->subcategory?->category?->name,
                'subcategory' => $e->subcategory?->name,
                'branch' => $e->branch?->name,
                'user' => $e->user?->name,
            ])
            ->values()
            ->all();

        return [
            'total' => $totalToday,
            'total_yesterday' => $totalYesterday,
            'count' => $countToday,
            'delta_pct' => $deltaPct,
            'hourly' => $hourly,
            'top_categories' => $topCategories,
            'recent' => $recent,
        ];
    }

    /**
     * Desglose por hora del día (7h a 19h). Devuelve [{h: '7', sales: 1234.5, trx: 12}].
     */
    private function hourlyBreakdown(int $branchId, string $date): array
    {
        $rows = DB::table('sales')
            ->where('branch_id', $branchId)
            ->where('status', SaleStatus::Completed->value)
            ->whereNull('deleted_at')
            ->whereDate('completed_at', $date)
            ->selectRaw('EXTRACT(HOUR FROM completed_at) as hour, COUNT(*) as trx, COALESCE(SUM(total), 0) as sales')
            ->groupBy('hour')
            ->get()
            ->keyBy(fn ($r) => (int) $r->hour);

        $out = [];
        for ($h = 7; $h <= 19; $h++) {
            $row = $rows->get($h);
            $out[] = [
                'h' => (string) $h,
                'sales' => $row ? (float) $row->sales : 0.0,
                'trx' => $row ? (int) $row->trx : 0,
            ];
        }

        return $out;
    }

    /**
     * Desglose por método de pago del día. Dinámico — agrupado por payments.method.
     */
    private function paymentMethodsBreakdown(int $branchId, string $date): array
    {
        return DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.branch_id', $branchId)
            ->whereNull('p.deleted_at')
            ->whereDate('p.created_at', $date)
            ->selectRaw('p.method as method, COALESCE(SUM(p.amount), 0) as total, COUNT(*) as count')
            ->groupBy('p.method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'method' => (string) $r->method,
                'label' => PaymentMethod::resolveLabel((string) $r->method),
                'total' => (float) $r->total,
                'count' => (int) $r->count,
            ])
            ->all();
    }
}
