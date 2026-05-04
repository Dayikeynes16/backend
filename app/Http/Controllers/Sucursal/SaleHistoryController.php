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

        // Historial muestra SOLO ventas cobradas (status=Completed) creadas
        // ese día — vista simple del cierre operativo. Métricas y Dashboard
        // siguen su propia lógica (canónica, basada en completed_at) para
        // análisis de negocio.
        $sales = Sale::where('branch_id', $branchId)
            ->with(['items', 'payments.user:id,name', 'payments.updatedByUser:id,name'])
            ->where('status', SaleStatus::Completed->value)
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
     * Resumen agregado de las ventas COBRADAS del día seleccionado (por
     * fecha de creación). Historial es la vista del cierre operativo —
     * "qué se cerró hoy" — sin los matices de canceladas/pendientes que
     * sí maneja Métricas.
     *
     * Reglas:
     * - Total vendido: ventas completed creadas ese día.
     * - Pagos por método: payments amarrados a esas mismas ventas.
     * - Métodos: lista de métodos activos de la sucursal (incluye los
     *   que tuvieron $0 ese día, para que el UI los muestre).
     */
    private function buildDaySummary(int $branchId, string $date, array $paymentMethods): array
    {
        // Conteos por status (todas las ventas creadas ese día — usados
        // internamente para el cálculo y como dato auxiliar en la respuesta).
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

        // Pagos por método de las ventas COMPLETED del día (por created_at).
        // Un pago hecho hoy sobre una venta de otro día NO entra acá.
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
