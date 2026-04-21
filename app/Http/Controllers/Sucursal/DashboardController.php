<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
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
            'tenant' => app('tenant'),
        ]);
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
                'label' => \App\Enums\PaymentMethod::resolveLabel((string) $r->method),
                'total' => (float) $r->total,
                'count' => (int) $r->count,
            ])
            ->all();
    }
}
