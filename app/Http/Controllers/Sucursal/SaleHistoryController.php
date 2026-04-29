<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SaleHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;
        $date = $request->date ?: now()->toDateString();

        $sales = Sale::where('branch_id', $branchId)
            ->with(['items', 'payments.user:id,name', 'payments.updatedByUser:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where('folio', 'ilike', "%{$s}%"))
            ->whereDate('created_at', $date)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        $user = Auth::user();
        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $canEditPayments = $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $paymentMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

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
            'daySummary' => $this->buildDaySummary($branchId, $date, $paymentMethods),
        ]);
    }

    /**
     * Resumen agregado de las ventas del día seleccionado.
     *
     * Reglas:
     * - Total vendido: SOLO ventas `completed` con created_at del día. Excluye
     *   cancelled, pending y active (vivas/no cerradas).
     * - Pagos por método: SUM de Payments amarrados a esas ventas completed.
     *   Esto excluye automáticamente pagos hechos hoy sobre ventas de otros
     *   días (porque la condición es DATE(sales.created_at) = $date).
     * - Métodos: se devuelve la lista de métodos activos de la sucursal,
     *   incluyendo los que tuvieron $0 ese día (para que el UI los muestre
     *   con valor 0 en lugar de ocultarlos).
     */
    private function buildDaySummary(int $branchId, string $date, array $paymentMethods): array
    {
        // Conteos por status (todas las ventas del día — útil para mostrar
        // transparencia: "X pendientes, Y canceladas").
        $statusCounts = Sale::where('branch_id', $branchId)
            ->whereDate('created_at', $date)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($r) => $r->status->value)
            ->map(fn ($r) => [
                'count' => (int) $r->count,
                'total' => (float) $r->total,
            ]);

        $byStatus = [
            'completed' => $statusCounts->get('completed') ?? ['count' => 0, 'total' => 0.0],
            'pending' => $statusCounts->get('pending') ?? ['count' => 0, 'total' => 0.0],
            'active' => $statusCounts->get('active') ?? ['count' => 0, 'total' => 0.0],
            'cancelled' => $statusCounts->get('cancelled') ?? ['count' => 0, 'total' => 0.0],
        ];

        $completedTotal = $byStatus['completed']['total'];
        $completedCount = $byStatus['completed']['count'];
        $avgTicket = $completedCount > 0 ? round($completedTotal / $completedCount, 2) : 0.0;

        // Pagos por método de las ventas COMPLETED del día. Excluye soft-deleted.
        // El JOIN con sales asegura que solo cuente pagos amarrados a ventas
        // cuya fecha de creación es la del día consultado — un pago hecho hoy
        // sobre una venta de ayer NO entra acá.
        $byMethodRows = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.branch_id', $branchId)
            ->where('s.status', SaleStatus::Completed->value)
            ->whereDate('s.created_at', $date)
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->selectRaw('p.method as method, COALESCE(SUM(p.amount), 0) as amount, COUNT(*) as count')
            ->groupBy('p.method')
            ->get()
            ->keyBy('method');

        $byMethod = [];
        foreach ($paymentMethods as $method) {
            $row = $byMethodRows->get($method);
            $byMethod[] = [
                'method' => $method,
                'amount' => $row ? (float) $row->amount : 0.0,
                'count' => $row ? (int) $row->count : 0,
            ];
        }

        return [
            'date' => $date,
            'total_sold' => (float) $completedTotal,
            'sale_count' => $completedCount,
            'avg_ticket' => (float) $avgTicket,
            'by_method' => $byMethod,
            'by_status' => $byStatus,
        ];
    }
}
