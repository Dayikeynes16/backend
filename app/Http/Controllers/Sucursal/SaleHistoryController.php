<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use App\Services\DailySummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SaleHistoryController extends Controller
{
    public function index(Request $request, DailySummaryService $summary): Response
    {
        $branchId = Auth::user()->branch_id;
        $tenantId = app('tenant')->id;
        $date = $request->date ?: now()->toDateString();

        // Sin búsqueda: ventas del día (fecha canónica COALESCE(completed_at,
        // created_at), igual que Métricas/Dashboard), solo cobradas o pendientes.
        // Buscar por folio ignora la fecha y el estado: busca en todo el historial
        // de la sucursal, así una venta de otro día aparece sin tener que cambiar la fecha.
        $sales = Sale::where('branch_id', $branchId)
            ->accountable()
            ->with(['items', 'payments.user:id,name', 'payments.updatedByUser:id,name', 'customer:id,name,phone'])
            ->when(
                $request->search,
                fn ($q, $term) => $q->where('folio', 'ilike', "%{$term}%"),
                fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, created_at)) = ?', [$date])
                    ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::Pending->value]),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        $user = Auth::user();
        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $canEditPayments = $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $paymentMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        // Resumen del día vía servicio centralizado (fuente única de verdad).
        $day = $summary->forDate($branchId, $tenantId, $date, $paymentMethods);
        $s = $day['sales'];
        $c = $day['collections'];

        // Forma que consume DaySummaryBar.vue.
        $daySummary = [
            'date' => $date,
            // Ventas netas del día (Completed + Pending por fecha canónica, sin canceladas).
            'total_sold' => $s['net_sales'],
            'sale_count' => $s['ticket_count'],
            'avg_ticket' => $s['avg_ticket'],
            'cancelled_amount' => $s['cancelled_amount'],
            'cancelled_count' => $s['cancelled_count'],
            // Cobranza: pagos creados ese día, con split por antigüedad de la venta.
            'total_collected' => $c['total'],
            'collected_from_today' => $c['from_today'],
            'collected_from_previous' => $c['from_previous'],
            'payment_count' => $c['payment_count'],
            // Desglose por método (desde payments, no desde sale.payment_method).
            'by_method' => $c['by_method'],
        ];

        return Inertia::render('Sucursal/Historial/Index', [
            'sales' => $sales,
            'filters' => $request->only('status', 'search', 'date'),
            'tenant' => app('tenant'),
            'paymentMethods' => $paymentMethods,
            'canEditPayments' => $canEditPayments,
            'canCancel' => $canEditPayments,
            'canManageStatus' => $canEditPayments,
            'branchInfo' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'ticket_config' => $branch->ticket_config,
            ],
            'daySummary' => $daySummary,
        ]);
    }
}
