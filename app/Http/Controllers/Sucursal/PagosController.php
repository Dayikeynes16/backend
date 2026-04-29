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
        $date = $request->date ?: now()->toDateString();

        // baseQuery aplica filtros del usuario (method, user_id) sobre el listado.
        // El resumen del día NO se filtra por method/user — siempre muestra el
        // panorama completo del día independiente de los filtros del listado.
        $baseQuery = Payment::whereHas('sale', fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->whereDate('payments.created_at', $date);

        $payments = $baseQuery
            ->with([
                'sale:id,folio,total,status,branch_id,amount_paid,amount_pending,created_at',
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

        return Inertia::render('Sucursal/Pagos/Index', [
            'payments' => $payments,
            'users' => $users,
            'filters' => $request->only(['method', 'user_id', 'date']),
            'tenant' => app('tenant'),
            'canEditPayments' => $canEditPayments,
            'paymentMethods' => $paymentMethods,
            'dailySummary' => $this->buildDailySummary($branchId, $date, $paymentMethods),
        ]);
    }

    /**
     * Resumen del día seleccionado para Pagos. Filtra por la fecha en que se
     * cobraron los pagos (`payments.created_at`) — incluye pagos hoy de ventas
     * de días anteriores. Excluye pagos soft-deleted (de ventas canceladas).
     */
    private function buildDailySummary(int $branchId, string $date, array $paymentMethods): array
    {
        // Agregación dinámica por método, sin importar cuántos haya activos.
        $byMethodRows = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.branch_id', $branchId)
            ->whereDate('p.created_at', $date)
            ->whereNull('p.deleted_at')
            ->selectRaw('p.method as method, COALESCE(SUM(p.amount), 0) as amount, COUNT(*) as count')
            ->groupBy('p.method')
            ->get()
            ->keyBy('method');

        $byMethod = [];
        $totalCollected = 0.0;
        $paymentCount = 0;
        foreach ($paymentMethods as $method) {
            $row = $byMethodRows->get($method);
            $amount = $row ? (float) $row->amount : 0.0;
            $count = $row ? (int) $row->count : 0;
            $byMethod[] = [
                'method' => $method,
                'amount' => $amount,
                'count' => $count,
            ];
            $totalCollected += $amount;
            $paymentCount += $count;
        }

        return [
            'date' => $date,
            'total_collected' => $totalCollected,
            'payment_count' => $paymentCount,
            'avg_payment' => $paymentCount > 0 ? round($totalCollected / $paymentCount, 2) : 0.0,
            'by_method' => $byMethod,
        ];
    }
}
