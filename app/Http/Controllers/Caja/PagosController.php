<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PagosController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $baseQuery = Payment::whereHas('sale', fn ($q) => $q->where('branch_id', $branchId))
            // Calificamos `payments.user_id` porque el clone para `$totals`
            // hace JOIN a `sales` (que también tiene `user_id`) y sin prefijo
            // Postgres lanza "column reference 'user_id' is ambiguous".
            ->where('payments.user_id', $user->id) // Cajero solo ve sus propios cobros
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when(
                $request->date,
                fn ($q, $d) => $q->whereDate('payments.created_at', $d),
                fn ($q) => $q->whereDate('payments.created_at', today())
            );

        // Totals con split de "ventas de hoy" vs "cuentas anteriores".
        // Necesitamos el JOIN a sales para clasificar por antigüedad.
        $totals = (clone $baseQuery)
            ->join('sales as s', 's.id', '=', 'payments.sale_id')
            ->select(DB::raw("
                COALESCE(SUM(payments.amount), 0) as total,
                COALESCE(SUM(CASE WHEN payments.method = 'cash' THEN payments.amount END), 0) as cash,
                COALESCE(SUM(CASE WHEN payments.method = 'card' THEN payments.amount END), 0) as card,
                COALESCE(SUM(CASE WHEN payments.method = 'transfer' THEN payments.amount END), 0) as transfer,
                COALESCE(SUM(CASE WHEN DATE(s.created_at) = DATE(payments.created_at) THEN payments.amount END), 0) as from_today,
                COALESCE(SUM(CASE WHEN DATE(s.created_at) < DATE(payments.created_at) THEN payments.amount END), 0) as from_previous
            "))
            ->first();

        $payments = $baseQuery
            ->with([
                'sale:id,folio,total,status,branch_id,amount_paid,amount_pending',
                'sale.payments' => fn ($q) => $q->with('user:id,name')->orderBy('created_at'),
                'user:id,name',
                'updatedByUser:id,name',
            ])
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->cursorPaginate(20)
            ->withQueryString();

        return Inertia::render('Caja/Pagos/Index', [
            'payments' => $payments,
            'totals' => $totals,
            'filters' => $request->only(['method', 'date']),
            'tenant' => app('tenant'),
        ]);
    }
}
