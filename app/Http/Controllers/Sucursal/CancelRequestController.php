<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Concerns\ResolvesMetricsRequest;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\RecalculateClosedShifts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class CancelRequestController extends Controller
{
    use ResolvesMetricsRequest;

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        // Solicitudes pendientes — independientes del rango.
        $requests = Sale::where('branch_id', $branchId)
            ->whereNotNull('cancel_requested_at')
            ->whereNull('cancelled_at')
            ->where('status', '!=', SaleStatus::Cancelled)
            ->with(['items', 'cancelRequestedByUser:id,name'])
            ->orderByDesc('cancel_requested_at')
            ->get();

        // Rango (mismo patrón que Métricas: preset/from/to). Default: hoy.
        $range = $this->resolveDateRange($request);

        // Stats del rango
        $cancelledQuery = Sale::where('branch_id', $branchId)
            ->where('status', SaleStatus::Cancelled)
            ->whereBetween('cancelled_at', [$range->start, $range->end]);

        $cancelledCount = (clone $cancelledQuery)->count();
        $cancelledTotal = (clone $cancelledQuery)->sum('total');

        // Motivos top del rango (antes: hardcodeado 30 días)
        $topReasons = Sale::where('branch_id', $branchId)
            ->where('status', SaleStatus::Cancelled)
            ->whereNotNull('cancel_reason')
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->select('cancel_reason', DB::raw('count(*) as count'), DB::raw('sum(total) as total'))
            ->groupBy('cancel_reason')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // Historial detallado del rango
        $history = Sale::where('branch_id', $branchId)
            ->where('status', SaleStatus::Cancelled)
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->with(['cancelledByUser:id,name', 'cancelRequestedByUser:id,name', 'items'])
            ->orderByDesc('cancelled_at')
            ->get();

        return Inertia::render('Sucursal/Cancelaciones/Index', [
            'requests' => $requests,
            'stats' => [
                'cancelled_count' => $cancelledCount,
                'cancelled_total' => round((float) $cancelledTotal, 2),
            ],
            'topReasons' => $topReasons,
            'history' => $history,
            // commonProps de Métricas (range + compare + selected_branch_id + statuses)
            // para que el componente DateRangeFilter pueda reutilizar useMetricsFilters.
            ...$this->commonProps($request, $range, $branchId),
        ]);
    }

    public function approve(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'cancel_reason' => 'nullable|string|max:500',
        ]);

        $cancelReason = $validated['cancel_reason'] ?? $sale->cancel_request_reason;

        if (! $cancelReason) {
            return back()->withErrors(['cancel_reason' => 'Debe indicar un motivo de cancelacion.']);
        }

        $wasCompleted = $sale->status === SaleStatus::Completed;

        DB::transaction(function () use ($sale, $user, $cancelReason, $wasCompleted) {
            $sale->payments()->delete();

            $sale->update([
                'status' => SaleStatus::Cancelled,
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $cancelReason,
            ]);

            if ($wasCompleted) {
                $this->recalculateAffectedShifts($sale);
            }
        });

        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        $msg = "Venta {$sale->folio} cancelada.";
        if ($wasCompleted) {
            $msg .= ' Los cortes de caja fueron recalculados.';
        }

        return back()->with('success', $msg);
    }

    private function recalculateAffectedShifts(Sale $sale): void
    {
        app(RecalculateClosedShifts::class)->forSale($sale);
    }

    public function reject(Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $sale->update([
            'cancel_requested_at' => null,
            'cancel_requested_by' => null,
            'cancel_request_reason' => null,
        ]);

        return back()->with('success', "Solicitud de cancelacion rechazada para {$sale->folio}.");
    }
}
