<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel de inicio del hub: KPIs del día de la sucursal, desglose por método de
 * pago, ventas recientes, top de productos y, si hay turno abierto, su resumen
 * de conciliación en vivo (reusa ShiftService::summary).
 */
class DashboardController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->branch_id;
        $today = today()->toDateString();

        // Ventas del día (no canceladas) de la sucursal.
        $salesAgg = Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereDate('created_at', $today)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(amount_pending), 0) AS pending')
            ->first();

        // Cobranza del día por método (pagos, no cancelados).
        $byMethod = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.branch_id', $branchId)
            ->whereDate('p.created_at', $today)
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN p.method = 'cash' THEN p.amount END), 0) AS cash,
                COALESCE(SUM(CASE WHEN p.method = 'card' THEN p.amount END), 0) AS card,
                COALESCE(SUM(CASE WHEN p.method = 'transfer' THEN p.amount END), 0) AS transfer,
                COALESCE(SUM(p.amount), 0) AS total")
            ->first();

        // Gastos en efectivo del día de la sucursal.
        $expensesTotal = (float) DB::table('expenses')
            ->where('branch_id', $branchId)
            ->whereDate('expense_at', $today)
            ->whereNull('deleted_at')
            ->whereNull('cancelled_by')
            ->sum('amount');

        $recent = Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereDate('created_at', $today)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'folio', 'total', 'status', 'created_at']);

        $topProducts = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.branch_id', $branchId)
            ->whereDate('s.created_at', $today)
            ->where('s.status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('si.deleted_at')
            ->whereNull('s.deleted_at')
            ->groupBy('si.product_name')
            ->selectRaw('si.product_name, COALESCE(SUM(si.subtotal), 0) AS amount, COUNT(*) AS lines')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $openShift = $this->shifts->current($user);

        return response()->json([
            'today' => [
                'sales_count' => (int) $salesAgg->cnt,
                'sales_total' => round((float) $salesAgg->total, 2),
                'pending_total' => round((float) $salesAgg->pending, 2),
                'expenses_total' => round($expensesTotal, 2),
                'collected_total' => round((float) $byMethod->total, 2),
            ],
            'by_method' => [
                'cash' => round((float) $byMethod->cash, 2),
                'card' => round((float) $byMethod->card, 2),
                'transfer' => round((float) $byMethod->transfer, 2),
            ],
            'recent_sales' => $recent->map(fn ($s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'total' => (float) $s->total,
                'status' => $s->status instanceof SaleStatus ? $s->status->value : $s->status,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
            'top_products' => $topProducts->map(fn ($r) => [
                'product_name' => $r->product_name,
                'amount' => round((float) $r->amount, 2),
            ])->values(),
            'shift' => $openShift ? $this->shifts->summary($openShift) : null,
        ]);
    }
}
