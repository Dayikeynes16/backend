<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\User;
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
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
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

        $users = User::where('branch_id', $branchId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $canEditPayments = $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin');

        return Inertia::render('Sucursal/Pagos/Index', [
            'payments' => $payments,
            'totals' => $totals,
            'users' => $users,
            'filters' => $request->only(['method', 'user_id', 'date']),
            'tenant' => app('tenant'),
            'canEditPayments' => $canEditPayments,
            'paymentMethods' => $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'],
        ]);
    }
}
