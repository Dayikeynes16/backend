<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel de inicio del hub. Espeja el dashboard de la web (admin-sucursal):
 * KPIs del día con comparativa vs ayer y ticket promedio, serie de ventas por
 * hora (hoy y ayer), cobranza por método, desglose de gastos, ventas/cortes
 * recientes y, si hay turno abierto, su conciliación en vivo.
 */
class DashboardController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $branchId = $user->branch_id;
        $today = today()->toDateString();
        $yesterday = today()->subDay()->toDateString();

        // ── Ventas: agregado de hoy y de ayer (no canceladas) ──────────────
        $salesAgg = fn (string $date) => Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereDate('created_at', $date)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(amount_pending), 0) AS pending, COUNT(*) FILTER (WHERE amount_pending > 0) AS pending_count')
            ->first();
        $st = $salesAgg($today);
        $sy = $salesAgg($yesterday);
        $avgTicket = $st->cnt > 0 ? round((float) $st->total / $st->cnt, 2) : 0.0;

        // ── Serie de ventas por hora (hoy y ayer), array 0..23 ─────────────
        $hourlyRows = fn (string $date) => DB::table('sales')
            ->where('branch_id', $branchId)
            ->whereDate('created_at', $date)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->selectRaw('EXTRACT(HOUR FROM created_at)::int AS h, COUNT(*) AS trx, COALESCE(SUM(total), 0) AS sales')
            ->groupBy('h')->get()->keyBy('h');
        $mkHourly = function ($rows) {
            $out = [];
            for ($h = 0; $h < 24; $h++) {
                $r = $rows->get($h);
                $out[] = ['h' => $h, 'sales' => $r ? round((float) $r->sales, 2) : 0.0, 'trx' => $r ? (int) $r->trx : 0];
            }

            return $out;
        };
        $hourlyToday = $mkHourly($hourlyRows($today));
        $hourlyYesterday = $mkHourly($hourlyRows($yesterday));

        // ── Cobranza del día por método (pagos) ────────────────────────────
        $bm = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.branch_id', $branchId)
            ->whereDate('p.created_at', $today)
            ->whereNull('p.deleted_at')->whereNull('s.deleted_at')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN p.method = 'cash' THEN p.amount END), 0) AS cash,
                COALESCE(SUM(CASE WHEN p.method = 'card' THEN p.amount END), 0) AS card,
                COALESCE(SUM(CASE WHEN p.method = 'transfer' THEN p.amount END), 0) AS transfer,
                COUNT(*) FILTER (WHERE p.method = 'cash') AS cash_count,
                COUNT(*) FILTER (WHERE p.method = 'card') AS card_count,
                COUNT(*) FILTER (WHERE p.method = 'transfer') AS transfer_count,
                COALESCE(SUM(p.amount), 0) AS total")
            ->first();

        // ── Gastos de hoy (con desglose) y total de ayer ───────────────────
        $expensesToday = Expense::where('branch_id', $branchId)
            ->whereDate('expense_at', $today)
            ->whereNull('cancelled_by')
            ->with('subcategory.category')
            ->orderByDesc('expense_at')
            ->get();
        $expTotal = round((float) $expensesToday->sum('amount'), 2);
        $expYesterday = round((float) Expense::where('branch_id', $branchId)
            ->whereDate('expense_at', $yesterday)->whereNull('cancelled_by')->sum('amount'), 2);

        $expHourlyGroups = $expensesToday->groupBy(fn ($e) => (int) $e->expense_at?->format('G'));
        $expensesHourly = [];
        for ($h = 0; $h < 24; $h++) {
            $expensesHourly[] = ['h' => $h, 'amount' => round((float) ($expHourlyGroups->get($h)?->sum('amount') ?? 0), 2)];
        }

        $topExpenseCategories = $expensesToday
            ->groupBy(fn ($e) => $e->subcategory?->category?->name ?? 'Sin categoría')
            ->map(fn ($grp, $name) => ['category' => $name, 'amount' => round((float) $grp->sum('amount'), 2)])
            ->sortByDesc('amount')->take(5)->values();

        $recentExpenses = $expensesToday->take(5)->map(fn ($e) => [
            'id' => $e->id,
            'concept' => $e->concept,
            'amount' => (float) $e->amount,
            'category' => $e->subcategory?->category?->name,
            'expense_at' => $e->expense_at?->toIso8601String(),
        ])->values();

        // ── Contadores ─────────────────────────────────────────────────────
        $productCount = Product::where('branch_id', $branchId)->where('status', 'active')->count();
        $cancelRequestCount = Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)->whereDate('created_at', $today)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->whereNotNull('cancel_requested_at')->count();

        // ── Ventas recientes / top productos / cortes recientes ────────────
        $recent = Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)->whereDate('created_at', $today)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->orderByDesc('created_at')->limit(8)
            ->get(['id', 'folio', 'total', 'status', 'created_at']);

        $topProducts = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.branch_id', $branchId)->whereDate('s.created_at', $today)
            ->where('s.status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('si.deleted_at')->whereNull('s.deleted_at')
            ->groupBy('si.product_name')
            ->selectRaw('si.product_name, COALESCE(SUM(si.subtotal), 0) AS amount, COUNT(*) AS lines')
            ->orderByDesc('amount')->limit(5)->get();

        $recentShifts = CashRegisterShift::where('branch_id', $branchId)
            ->whereNotNull('closed_at')
            ->with('user:id,name')
            ->orderByDesc('closed_at')->limit(5)->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'user' => $s->user ? ['name' => $s->user->name] : null,
                'closed_at' => $s->closed_at?->toIso8601String(),
                'total_sales' => (float) $s->total_sales,
                'sale_count' => (int) $s->sale_count,
            ])->values();

        $openShift = $this->shifts->current($user);

        return response()->json([
            'today' => [
                'sales_count' => (int) $st->cnt,
                'sales_total' => round((float) $st->total, 2),
                'sales_total_yesterday' => round((float) $sy->total, 2),
                'sales_delta_pct' => $this->deltaPct((float) $sy->total, (float) $st->total),
                'avg_ticket' => $avgTicket,
                'pending_total' => round((float) $st->pending, 2),
                'pending_count' => (int) $st->pending_count,
                'expenses_total' => $expTotal,
                'expenses_total_yesterday' => $expYesterday,
                'expenses_delta_pct' => $this->deltaPct($expYesterday, $expTotal),
                'expenses_count' => $expensesToday->count(),
                'collected_total' => round((float) $bm->total, 2),
                'cancel_request_count' => $cancelRequestCount,
                'product_count' => $productCount,
            ],
            'by_method' => [
                'cash' => round((float) $bm->cash, 2),
                'card' => round((float) $bm->card, 2),
                'transfer' => round((float) $bm->transfer, 2),
            ],
            'by_method_count' => [
                'cash' => (int) $bm->cash_count,
                'card' => (int) $bm->card_count,
                'transfer' => (int) $bm->transfer_count,
            ],
            'hourly' => $hourlyToday,
            'hourly_yesterday' => $hourlyYesterday,
            'expenses_hourly' => $expensesHourly,
            'top_products' => $topProducts->map(fn ($r) => [
                'product_name' => $r->product_name,
                'amount' => round((float) $r->amount, 2),
            ])->values(),
            'top_expense_categories' => $topExpenseCategories,
            'recent_sales' => $recent->map(fn ($s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'total' => (float) $s->total,
                'status' => $s->status instanceof SaleStatus ? $s->status->value : $s->status,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
            'recent_expenses' => $recentExpenses,
            'recent_shifts' => $recentShifts,
            'shift' => $openShift ? $this->shifts->summary($openShift) : null,
        ]);
    }

    /** Variación porcentual vs una base; null si no hay base de comparación. */
    private function deltaPct(float $prev, float $current): ?float
    {
        if ($prev <= 0.0) {
            return null;
        }

        return round((($current - $prev) / $prev) * 100, 1);
    }
}
