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
            ->where('user_id', $user->id) // Cajero solo ve sus propios cobros
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when(
                $request->date,
                fn ($q, $d) => $q->whereDate('payments.created_at', $d),
                fn ($q) => $q->whereDate('payments.created_at', today())
            );

        // Totals (same filters, no pagination)
        $totals = (clone $baseQuery)
            ->select(DB::raw("
                COALESCE(SUM(amount), 0) as total,
                COALESCE(SUM(CASE WHEN method = 'cash' THEN amount END), 0) as cash,
                COALESCE(SUM(CASE WHEN method = 'card' THEN amount END), 0) as card,
                COALESCE(SUM(CASE WHEN method = 'transfer' THEN amount END), 0) as transfer
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
