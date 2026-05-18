<?php

namespace App\Http\Controllers\Empresa;

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
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard de Empresa — agregado tenant-wide con filtro opcional por sucursal.
 *
 * Devuelve la MISMA estructura que Sucursal\DashboardController para que el
 * frontend reutilice el mismo componente DashboardOverview.vue. Cuando el
 * usuario elige una sucursal, los datos coinciden con el dashboard de esa
 * sucursal. Cuando elige "Todas", se agrega a nivel tenant.
 */
class DashboardController extends Controller
{
    public function index(Request $request, DailySummaryService $summary): Response
    {
        $tenant = app('tenant');
        $tz = config('app.timezone');
        $date = $request->date ?: now($tz)->toDateString();
        $dateObj = CarbonImmutable::parse($date, $tz);
        $yesterday = $dateObj->subDay()->toDateString();

        $branchFilter = $request->integer('branch_id') ?: null;
        $scopeFilter = $branchFilter
            ? ['branch_id' => $branchFilter]
            : ['tenant_id' => $tenant->id];

        // Chip "Incluir Completadas/Pendientes" — default solo completed.
        $statuses = $this->sanitizeStatuses($request->input('statuses'));

        // --- Fuente única de verdad: DailySummaryService (delega en SalesMetrics) ---
        // Fecha canónica: COALESCE(completed_at, created_at) — la misma que Métricas.
        $day = $summary->forDate($branchFilter, $tenant->id, $date, ['cash', 'card', 'transfer'], null, $statuses);
        $s = $day['sales'];
        $sy = $day['sales_yesterday'];
        $c = $day['collections'];

        $totals = [
            'net_sales' => $s['net_sales'],
            'net_sales_yesterday' => $sy['net_sales'],
            'delta_pct' => $day['delta_pct'],
            'sale_count' => $s['ticket_count'],
            'sale_count_yesterday' => $sy['ticket_count'],
            'avg_ticket' => $s['avg_ticket'],
            'cancelled_amount' => $s['cancelled_amount'],
            'cancelled_count' => $s['cancelled_count'],
            'total_collected' => $c['total'],
            'collected_from_today' => $c['from_today'],
            'collected_from_previous' => $c['from_previous'],
        ];

        // Ventas por hora — hoy vs ayer. La ventana se adapta a las ventas reales
        // (de la primera a la última hora con ventas, hoy o ayer); si no hubo, 7h–19h.
        $hourlyToday = $summary->hourlySeries($branchFilter, $tenant->id, $date, $statuses);
        $hourlyYesterday = $summary->hourlySeries($branchFilter, $tenant->id, $yesterday, $statuses);
        [$startHour, $endHour] = $this->hourlyWindow($hourlyToday, $hourlyYesterday);
        $hoursData = $this->shapeHourly($hourlyToday, $startHour, $endHour);
        $yesterdayHoursData = $this->shapeHourly($hourlyYesterday, $startHour, $endHour);

        $topProducts = SaleItem::select('product_name', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(subtotal) as total_revenue'))
            ->whereHas('sale', fn ($q) => $this->applyFilter($q, $scopeFilter)
                ->whereIn('status', $statuses)
                ->whereNull('cancelled_at')
                ->whereRaw('DATE(COALESCE(completed_at, created_at)) = ?', [$date])
            )
            ->groupBy('product_name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        $recentShifts = CashRegisterShift::query()
            ->where(fn ($q) => $this->applyFilter($q, $scopeFilter))
            ->whereNotNull('closed_at')
            ->with(['user:id,name', 'branch:id,name'])
            ->orderByDesc('closed_at')
            ->limit(5)
            ->get();

        $pendingCount = Sale::query()
            ->where(fn ($q) => $this->applyFilter($q, $scopeFilter))
            ->where('status', SaleStatus::Pending)
            ->count();

        $cancelRequestCount = Sale::query()
            ->where(fn ($q) => $this->applyFilter($q, $scopeFilter))
            ->whereNotNull('cancel_requested_at')
            ->whereNull('cancelled_at')
            ->where('status', '!=', SaleStatus::Cancelled)
            ->count();

        $productCount = Product::query()
            ->where(fn ($q) => $this->applyFilter($q, $scopeFilter))
            ->where('status', 'active')
            ->count();

        $cajeroCount = User::where('tenant_id', $tenant->id)
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
            ->whereHas('roles', fn ($q) => $q->where('name', 'cajero'))
            ->count();

        $activeCashierCount = CashRegisterShift::query()
            ->where(fn ($q) => $this->applyFilter($q, $scopeFilter))
            ->whereNull('closed_at')
            ->whereDate('opened_at', '<=', $date)
            ->count();

        $expenses = $this->expensesSnapshot($scopeFilter, $date, $yesterday);

        $branches = Branch::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        return Inertia::render('Empresa/Dashboard', [
            'totals' => $totals,
            'hoursData' => $hoursData,
            'yesterdayHoursData' => $yesterdayHoursData,
            'paymentMethods' => $c['by_method'],
            'topProducts' => $topProducts,
            'recentShifts' => $recentShifts,
            'pendingCount' => $pendingCount,
            'cancelRequestCount' => $cancelRequestCount,
            'productCount' => $productCount,
            'cajeroCount' => $cajeroCount,
            'activeCashierCount' => $activeCashierCount,
            'expenses' => $expenses,
            'selectedDate' => $date,
            'selectedBranch' => $branchFilter,
            'selectedStatuses' => $statuses,
            'branches' => $branches,
            'branchCount' => $branches->where('status', 'active')->count(),
            'tenant' => $tenant,
        ]);
    }

    /**
     * Sanitiza el chip "Incluir" del dashboard. Acepta sólo completed/pending.
     *
     * @return list<string>
     */
    private function sanitizeStatuses(mixed $raw): array
    {
        $allowed = ['completed', 'pending'];
        $values = is_array($raw) ? $raw : (is_string($raw) ? [$raw] : []);
        $clean = array_values(array_intersect($allowed, $values));

        return $clean === [] ? ['completed'] : $clean;
    }

    private function applyFilter($query, array $filter)
    {
        foreach ($filter as $col => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            $query->where($col, $val);
        }

        return $query;
    }

    /**
     * Ventana de horas a pintar en el chart "ventas por hora": de la primera a
     * la última hora con ventas (hoy o ayer), con 1h de margen a cada lado.
     * Si no hubo ventas en ninguno de los dos días, ventana por defecto 7h–19h.
     *
     * @param  array<int, array{trx: int, total: float}>  $today
     * @param  array<int, array{trx: int, total: float}>  $yesterday
     * @return array{0: int, 1: int} [horaInicio, horaFin] (0–23)
     */
    private function hourlyWindow(array $today, array $yesterday): array
    {
        $hours = array_keys($today + $yesterday);
        if ($hours === []) {
            return [7, 19];
        }

        return [max(0, min($hours) - 1), min(23, max($hours) + 1)];
    }

    /**
     * Convierte el mapa hora→{trx,total} de SalesMetrics al formato del chart
     * "ventas por hora": una entrada por cada hora del rango [$startHour, $endHour],
     * con ceros donde no hubo ventas.
     *
     * @param  array<int, array{trx: int, total: float}>  $byHour
     * @return list<array{h: string, sales: float, trx: int}>
     */
    private function shapeHourly(array $byHour, int $startHour, int $endHour): array
    {
        $out = [];
        for ($h = $startHour; $h <= $endHour; $h++) {
            $out[] = [
                'h' => (string) $h,
                'sales' => (float) ($byHour[$h]['total'] ?? 0),
                'trx' => (int) ($byHour[$h]['trx'] ?? 0),
            ];
        }

        return $out;
    }

    private function expensesSnapshot(array $filter, string $date, string $yesterday): array
    {
        $base = fn () => Expense::query()->where(fn ($q) => $this->applyFilter($q, $filter));

        $totalToday = (float) $base()->whereDate('expense_at', $date)->sum('amount');
        $totalYesterday = (float) $base()->whereDate('expense_at', $yesterday)->sum('amount');
        $countToday = (int) $base()->whereDate('expense_at', $date)->count();

        $deltaPct = $totalYesterday > 0
            ? round((($totalToday - $totalYesterday) / $totalYesterday) * 100, 1)
            : null;

        $perHour = DB::table('expenses')
            ->where(fn ($q) => $this->applyFilter($q, $filter))
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

        $topCategories = $base()
            ->whereDate('expense_at', $date)
            ->select('expense_subcategory_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('expense_subcategory_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with(['subcategory:id,expense_category_id,name', 'subcategory.category:id,name'])
            ->get()
            ->map(fn ($r) => [
                'subcategory' => $r->subcategory?->name,
                'category' => $r->subcategory?->category?->name,
                'total' => (float) $r->total,
                'count' => (int) $r->count,
            ])
            ->values()
            ->all();

        $recent = $base()
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
}
