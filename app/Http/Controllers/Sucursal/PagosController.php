<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\User;
use App\Services\DailySummaryService;
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
        $date = $request->date ?: now()->toDateString();

        // baseQuery aplica los filtros del usuario (method, user_id, customer)
        // sobre el listado. El resumen del día NO se filtra — siempre muestra el
        // panorama completo del día independiente de los filtros del listado.
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
            ->whereDate('payments.created_at', $date);

        $payments = $baseQuery
            ->with([
                'sale:id,folio,total,status,branch_id,amount_paid,amount_pending,created_at,customer_id',
                'sale.customer:id,name',
                'sale.payments' => fn ($q) => $q->with('user:id,name')->orderBy('created_at'),
                'user:id,name',
                'updatedByUser:id,name',
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

        // Resumen del día vía servicio centralizado (fuente única de verdad).
        $day = $summary->forDate($branchId, $tenantId, $date, $paymentMethods);
        $c = $day['collections'];

        $dailySummary = [
            'date' => $date,
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
            'filters' => $request->only(['method', 'user_id', 'date', 'customer']),
            'tenant' => app('tenant'),
            'canEditPayments' => $canEditPayments,
            'paymentMethods' => $paymentMethods,
            'dailySummary' => $dailySummary,
        ]);
    }
}
