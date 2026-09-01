<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\User;
use App\Services\DailySummaryService;
use App\Services\Metrics\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PagosController extends Controller
{
    public function index(Request $request, DailySummaryService $summary): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;
        $tenantId = app('tenant')->id;

        // Rango en vez de un solo día. `date` se sigue admitiendo porque hay
        // enlaces guardados y accesos directos que lo llevan: equivale al rango
        // de ese día.
        $legacyDate = $request->query('date');
        $range = DateRange::fromRequest(
            $request->query('preset'),
            $request->query('from') ?: $legacyDate,
            $request->query('to') ?: $legacyDate,
        );

        // baseQuery aplica los filtros del usuario (method, user_id, customer)
        // sobre el listado. El resumen del día se filtra SÓLO por cajero
        // (user_id) cuando aplica — el resto de filtros no afectan los KPIs
        // del header para preservar el panorama por método de pago.
        // customer: 'with' = pagos de ventas con cliente, 'without' = de mostrador.
        $baseQuery = Payment::whereHas('sale', function ($q) use ($branchId, $request) {
            $q->where('branch_id', $branchId);
            if ($request->customer === 'with') {
                $q->whereNotNull('customer_id');
            } elseif ($request->customer === 'without') {
                $q->whereNull('customer_id');
            }
        })
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->whereBetween('payments.created_at', [$range->start, $range->end]);

        // Un cobro global (FIFO) reparte un pago grande en varios pagos hijos
        // (mismo customer_payment_id). En la lista lo colapsamos a un solo
        // renglón dejando un representante; los KPIs de arriba ya sumaron todo.
        $payments = $baseQuery
            ->where(function ($q) {
                $q->whereNull('payments.customer_payment_id')
                    ->orWhereIn('payments.id', function ($sub) {
                        $sub->from('payments')->selectRaw('MIN(id)')
                            ->whereNotNull('customer_payment_id')
                            ->groupBy('customer_payment_id');
                    });
            })
            ->with([
                'sale:id,folio,total,status,branch_id,amount_paid,amount_pending,created_at,customer_id',
                'sale.customer:id,name',
                'sale.payments' => fn ($q) => $q->with([
                    'user:id,name',
                    'receipts:id,payment_id,customer_payment_id,original_name,mime_type,size_bytes',
                ])->orderBy('created_at'),
                'user:id,name',
                'updatedByUser:id,name',
                'customerPayment:id,folio,customer_id,amount_applied,method,user_id,created_at',
                'customerPayment.customer:id,name',
                'customerPayment.user:id,name',
                'customerPayment.receipts:id,payment_id,customer_payment_id,original_name,mime_type,size_bytes',
                // Ventas que abonó el cobro global: sin esto el panel se corta en el
                // folio del cobro y nunca dice a dónde se fue el dinero.
                'customerPayment.payments:id,customer_payment_id,sale_id,amount',
                'customerPayment.payments.sale:id,folio,status,created_at',
                'receipts:id,payment_id,customer_payment_id,original_name,mime_type,size_bytes',
            ])
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->cursorPaginate(20)
            ->withQueryString();

        $users = User::where('branch_id', $branchId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $paymentMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];
        $canEditPayments = $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin');

        // Resumen del periodo vía servicio centralizado. Pasamos user_id sólo
        // si viene en filtros, para que el resumen refleje "Total cobrado por X".
        $filterUserId = $request->user_id ? (int) $request->user_id : null;
        $c = $summary->collectionsForRange($range, $branchId, $tenantId, $paymentMethods, $filterUserId);

        $periodSummary = [
            'total_collected' => $c['total'],
            'collected_from_today' => $c['from_today'],
            'collected_from_previous' => $c['from_previous'],
            'payment_count' => $c['payment_count'],
            'avg_payment' => $c['payment_count'] > 0 ? round($c['total'] / $c['payment_count'], 2) : 0.0,
            'by_method' => $c['by_method'],
        ];

        return Inertia::render('Sucursal/Pagos/Index', [
            'payments' => $payments,
            'users' => $users,
            'filters' => $request->only(['method', 'user_id', 'customer']),
            'range' => $range->toArray(),
            'tenant' => app('tenant'),
            'canEditPayments' => $canEditPayments,
            'paymentMethods' => $paymentMethods,
            'periodSummary' => $periodSummary,
            'paymentReceiptsEnabled' => (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required),
        ]);
    }
}
