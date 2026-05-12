<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\DailySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, DailySummaryService $summary): Response
    {
        $branchId = Auth::user()->branch_id;
        $tenantId = app('tenant')->id;
        $tz = config('app.timezone');
        $date = $request->date ?: now($tz)->toDateString();
        $yesterday = CarbonImmutable::parse($date, $tz)->subDay()->toDateString();

        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $enabledMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        // --- Fuente única de verdad: DailySummaryService (delega en SalesMetrics) ---
        // Fecha canónica: COALESCE(completed_at, created_at) — la misma que Métricas.
        $day = $summary->forDate($branchId, $tenantId, $date, $enabledMethods);
        $s = $day['sales'];
        $sy = $day['sales_yesterday'];
        $c = $day['collections'];

        $totals = [
            // Ventas netas del día (cobradas o pendientes, excluye canceladas).
            'net_sales' => $s['net_sales'],
            'net_sales_yesterday' => $sy['net_sales'],
            'delta_pct' => $day['delta_pct'],
            'sale_count' => $s['ticket_count'],
            'sale_count_yesterday' => $sy['ticket_count'],
            'avg_ticket' => $s['avg_ticket'],
            'cancelled_amount' => $s['cancelled_amount'],
            'cancelled_count' => $s['cancelled_count'],
            // Cobranza del día: pagos creados hoy (puede incluir abonos a ventas anteriores).
            'total_collected' => $c['total'],
            'collected_from_today' => $c['from_today'],
            'collected_from_previous' => $c['from_previous'],
        ];

        // Ventas por hora (hoy y ayer) — rango 7h a 19h (13 horas = rango operativo típico).
        $hoursData = $this->shapeHourly($summary->hourlySeries($branchId, $tenantId, $date));
        $yesterdayHoursData = $this->shapeHourly($summary->hourlySeries($branchId, $tenantId, $yesterday));

        // Top productos del día (ventas no canceladas, por fecha canónica).
        $topProducts = SaleItem::select('product_name', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(subtotal) as total_revenue'))
            ->whereHas('sale', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::Pending->value])
                ->whereNull('cancelled_at')
                ->whereRaw('DATE(COALESCE(completed_at, created_at)) = ?', [$date])
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
            // paymentMethods ahora viene del servicio centralizado (collections.by_method).
            'paymentMethods' => $c['by_method'],
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
     * Convierte el mapa hora→{trx,total} de SalesMetrics al formato del chart
     * "ventas por hora": lista fija de 7h a 19h con ceros donde no hubo ventas.
     *
     * @param  array<int, array{trx: int, total: float}>  $byHour
     * @return list<array{h: string, sales: float, trx: int}>
     */
    private function shapeHourly(array $byHour): array
    {
        $out = [];
        for ($h = 7; $h <= 19; $h++) {
            $out[] = [
                'h' => (string) $h,
                'sales' => (float) ($byHour[$h]['total'] ?? 0),
                'trx' => (int) ($byHour[$h]['trx'] ?? 0),
            ];
        }

        return $out;
    }
}
