<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\PhoneNormalizer;
use App\Services\WhatsappMessageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerStatsController extends Controller
{
    /**
     * Dashboard metrics for a single customer.
     * Excludes cancelled sales from every calculation.
     */
    public function stats(Customer $customer): JsonResponse
    {
        $this->authorizeAccess($customer);

        $baseSales = Sale::where('customer_id', $customer->id)
            ->where('status', '!=', SaleStatus::Cancelled->value);

        $totals = (clone $baseSales)
            ->selectRaw('
                COUNT(*)          as sale_count,
                COALESCE(SUM(total), 0)          as total_spent,
                COALESCE(AVG(total), 0)          as avg_ticket,
                COALESCE(SUM(amount_pending), 0) as total_owed,
                COALESCE(SUM(amount_paid), 0)    as total_paid,
                MIN(created_at) as first_sale_at,
                MAX(created_at) as last_sale_at
            ')
            ->first();

        $lastSale = (clone $baseSales)
            ->orderByDesc('created_at')
            ->first(['id', 'folio', 'total', 'created_at', 'status']);

        // Savings: sum over sale_items of (original_unit_price - unit_price) * quantity
        $savings = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.customer_id', $customer->id)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->selectRaw('
                COALESCE(SUM(GREATEST(sale_items.original_unit_price - sale_items.unit_price, 0) * sale_items.quantity), 0) as total_saved,
                COALESCE(SUM(sale_items.original_unit_price * sale_items.quantity), 0) as total_at_catalog
            ')
            ->first();

        $totalSaved = (float) $savings->total_saved;
        $totalAtCatalog = (float) $savings->total_at_catalog;
        $avgDiscountPct = $totalAtCatalog > 0
            ? round(($totalSaved / $totalAtCatalog) * 100, 2)
            : 0.0;

        // Frecuencia
        $saleCount = (int) $totals->sale_count;
        $avgDaysBetween = null;
        $salesPerMonth = null;

        if ($saleCount >= 2 && $totals->first_sale_at && $totals->last_sale_at) {
            $first = Carbon::parse($totals->first_sale_at);
            $last = Carbon::parse($totals->last_sale_at);
            $spanDays = max($first->diffInDays($last), 1);
            $avgDaysBetween = round($spanDays / max($saleCount - 1, 1), 1);

            $spanMonths = max($first->diffInMonths($last), 1);
            $salesPerMonth = round($saleCount / $spanMonths, 2);
        } elseif ($saleCount === 1) {
            $salesPerMonth = 1.0;
        }

        // Pending sales count (for UI badge)
        $pendingSalesCount = (clone $baseSales)
            ->where('amount_pending', '>', 0)
            ->count();

        // Shift status del user actual — para habilitar botón de cobro global
        $currentUserShiftOpen = CashRegisterShift::where('user_id', Auth::id())
            ->whereNull('closed_at')
            ->exists();

        return response()->json([
            'sale_count' => $saleCount,
            'total_spent' => round((float) $totals->total_spent, 2),
            'avg_ticket' => round((float) $totals->avg_ticket, 2),
            'total_saved' => round($totalSaved, 2),
            'avg_discount_pct' => $avgDiscountPct,
            'total_owed' => round((float) $totals->total_owed, 2),
            'total_paid' => round((float) $totals->total_paid, 2),
            'pending_sales_count' => $pendingSalesCount,
            'first_sale_at' => $totals->first_sale_at,
            'last_sale' => $lastSale ? [
                'id' => $lastSale->id,
                'folio' => $lastSale->folio,
                'total' => (float) $lastSale->total,
                'created_at' => $lastSale->created_at,
                'status' => $lastSale->status,
            ] : null,
            'avg_days_between' => $avgDaysBetween,
            'sales_per_month' => $salesPerMonth,
            'current_user_shift_open' => $currentUserShiftOpen,
        ]);
    }

    /**
     * Paginated purchase history with optional date range.
     */
    public function history(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAccess($customer);

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = $validated['per_page'] ?? 25;

        $sales = Sale::where('customer_id', $customer->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->with([
                'items:id,sale_id,product_name,quantity,unit_type,unit_price,original_unit_price,subtotal',
                'payments:id,sale_id,method,amount,created_at',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($sales);
    }

    /**
     * Top products for this customer, by quantity and by money spent.
     */
    public function topProducts(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAccess($customer);

        $limit = (int) $request->input('limit', 10);
        $limit = max(1, min($limit, 50));

        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.customer_id', $customer->id)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->selectRaw('
                sale_items.product_id,
                MAX(sale_items.product_name) as product_name,
                MAX(sale_items.unit_type) as unit_type,
                SUM(sale_items.quantity) as total_quantity,
                SUM(sale_items.subtotal) as total_spent,
                SUM(GREATEST(sale_items.original_unit_price - sale_items.unit_price, 0) * sale_items.quantity) as total_saved,
                COUNT(DISTINCT sale_items.sale_id) as times_bought
            ')
            ->groupBy('sale_items.product_id')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get();

        return response()->json([
            'items' => $rows->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_name' => $r->product_name,
                'unit_type' => $r->unit_type,
                'total_quantity' => round((float) $r->total_quantity, 3),
                'total_spent' => round((float) $r->total_spent, 2),
                'total_saved' => round((float) $r->total_saved, 2),
                'times_bought' => (int) $r->times_bought,
            ]),
        ]);
    }

    /**
     * Single sale detail for modal (items + payments + cashier).
     */
    public function saleDetail(
        Customer $customer,
        Sale $sale,
        WhatsappMessageService $whatsappService,
    ): JsonResponse {
        $this->authorizeAccess($customer);

        if ($sale->customer_id !== $customer->id) {
            abort(404);
        }

        $sale->load([
            'items:id,sale_id,product_name,quantity,unit_type,unit_price,original_unit_price,subtotal',
            'payments:id,sale_id,method,amount,created_at,user_id',
            'payments.user:id,name',
            'user:id,name',
        ]);

        // Build WhatsApp link for the customer if they have a phone configured.
        // Customer.phone is NOT NULL in schema but we guard defensively.
        $whatsappUrl = null;
        if (! empty($customer->phone)) {
            $normalized = PhoneNormalizer::normalize($customer->phone);
            if ($normalized !== '') {
                $text = $whatsappService->buildCustomerSaleText($sale);
                $whatsappUrl = $whatsappService->buildUrl($normalized, $text);
            }
        }

        return response()->json([
            'id' => $sale->id,
            'folio' => $sale->folio,
            'status' => $sale->status,
            'payment_method' => $sale->payment_method,
            'total' => (float) $sale->total,
            'amount_paid' => (float) $sale->amount_paid,
            'amount_pending' => (float) $sale->amount_pending,
            'origin' => $sale->origin,
            'origin_name' => $sale->origin_name,
            'created_at' => $sale->created_at,
            'completed_at' => $sale->completed_at,
            'cashier' => $sale->user ? ['id' => $sale->user->id, 'name' => $sale->user->name] : null,
            'items' => $sale->items,
            'payments' => $sale->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'created_at' => $p->created_at,
                'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
            ]),
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'has_phone' => ! empty($customer->phone),
            ],
            'whatsapp_url' => $whatsappUrl,
        ]);
    }

    /**
     * Payment history + outstanding sales.
     * Returns a unified `recent_movements` feed mixing global customer_payments
     * and standalone (single-sale) payments, chronologically sorted.
     */
    public function payments(Customer $customer): JsonResponse
    {
        $this->authorizeAccess($customer);

        $pendingSales = Sale::where('customer_id', $customer->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->where('amount_pending', '>', 0)
            ->orderByDesc('created_at')
            ->get(['id', 'folio', 'total', 'amount_paid', 'amount_pending', 'status', 'created_at']);

        // Movimientos globales
        $globals = CustomerPayment::where('customer_id', $customer->id)
            ->where('branch_id', $customer->branch_id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($cp) => [
                'type' => 'global',
                'id' => $cp->id,
                'folio' => $cp->folio,
                'method' => $cp->method,
                'amount_received' => (float) $cp->amount_received,
                'amount_applied' => (float) $cp->amount_applied,
                'change_given' => (float) $cp->change_given,
                'sales_affected_count' => $cp->sales_affected_count,
                'cashier_name' => $cp->user?->name,
                'created_at' => $cp->created_at,
                'sort_key' => $cp->created_at?->timestamp ?? 0,
            ]);

        // Pagos individuales (sin customer_payment_id) asociados a ventas de este cliente
        $singles = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->leftJoin('users', 'users.id', '=', 'payments.user_id')
            ->where('sales.customer_id', $customer->id)
            ->whereNull('payments.customer_payment_id')
            ->whereNull('payments.deleted_at')
            ->select(
                'payments.id',
                'payments.sale_id',
                'sales.folio as sale_folio',
                'payments.method',
                'payments.amount',
                'payments.created_at',
                'users.name as cashier_name'
            )
            ->orderByDesc('payments.created_at')
            ->limit(100)
            ->get()
            ->map(fn ($p) => [
                'type' => 'single',
                'id' => $p->id,
                'sale_id' => $p->sale_id,
                'sale_folio' => $p->sale_folio,
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'cashier_name' => $p->cashier_name,
                'created_at' => $p->created_at,
                'sort_key' => strtotime($p->created_at),
            ]);

        $recentMovements = $globals
            ->concat($singles)
            ->sortByDesc('sort_key')
            ->values()
            ->take(100)
            ->map(function ($m) {
                unset($m['sort_key']);

                return $m;
            });

        return response()->json([
            'pending_sales' => $pendingSales,
            'recent_movements' => $recentMovements->values(),
            'total_owed' => round((float) $pendingSales->sum('amount_pending'), 2),
        ]);
    }

    private function authorizeAccess(Customer $customer): void
    {
        $user = Auth::user();
        if ($customer->branch_id !== $user->branch_id) {
            abort(403);
        }
    }
}
