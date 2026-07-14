<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\RecalculateClosedShifts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aprobación/rechazo de solicitudes de cancelación desde el hub, exclusivo de
 * admin-sucursal. Paridad con Sucursal\CancelRequestController: aprobar borra
 * los pagos, marca Cancelled y recalcula cortes cerrados si estaba completada.
 */
class CancelRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $branchId = $request->user()->branch_id;

        // Rango para stats/historial (default: hoy, como la web).
        $from = $request->filled('from') ? $request->date('from')->startOfDay() : now()->startOfDay();
        $to = $request->filled('to') ? $request->date('to')->endOfDay() : now()->endOfDay();

        // Solicitudes pendientes — independientes del rango.
        $requests = Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereNotNull('cancel_requested_at')
            ->whereNull('cancelled_at')
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->with(['items', 'cancelRequestedByUser:id,name', 'customer:id,name'])
            ->orderByDesc('cancel_requested_at')
            ->get();

        $cancelledQuery = fn () => Sale::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', SaleStatus::Cancelled->value)
            ->whereBetween('cancelled_at', [$from, $to]);

        $topReasons = $cancelledQuery()
            ->whereNotNull('cancel_reason')
            ->select('cancel_reason', DB::raw('count(*) as count'), DB::raw('sum(total) as total'))
            ->groupBy('cancel_reason')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'reason' => $r->cancel_reason,
                'count' => (int) $r->count,
                'total' => round((float) $r->total, 2),
            ]);

        $history = $cancelledQuery()
            ->with(['cancelledByUser:id,name', 'cancelRequestedByUser:id,name'])
            ->orderByDesc('cancelled_at')
            ->limit(100)
            ->get();

        return response()->json([
            'requests' => $requests->map(fn (Sale $s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'total' => (float) $s->total,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'amount_paid' => (float) $s->amount_paid,
                'created_at' => $s->created_at?->toIso8601String(),
                'customer' => $s->customer?->name,
                'items_count' => $s->items->count(),
                'items' => $s->items->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'quantity' => (float) $i->quantity,
                    'subtotal' => (float) $i->subtotal,
                ])->values(),
                'requested_by' => $s->cancelRequestedByUser?->name,
                'requested_at' => $s->cancel_requested_at,
                'reason' => $s->cancel_request_reason,
            ])->values(),
            'stats' => [
                'cancelled_count' => $cancelledQuery()->count(),
                'cancelled_total' => round((float) $cancelledQuery()->sum('total'), 2),
            ],
            'top_reasons' => $topReasons,
            'history' => $history->map(fn (Sale $s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'total' => (float) $s->total,
                'cancelled_at' => $s->cancelled_at?->toIso8601String(),
                'cancel_reason' => $s->cancel_reason,
                'cancelled_by' => $s->cancelledByUser?->name,
                'requested_by' => $s->cancelRequestedByUser?->name,
            ])->values(),
        ]);
    }

    public function approve(Request $request, int $sale): JsonResponse
    {
        $this->ensureAdmin($request);
        $user = $request->user();
        $found = $this->findSale($request, $sale);

        $validated = $request->validate([
            'cancel_reason' => 'nullable|string|max:500',
        ]);

        $cancelReason = $validated['cancel_reason'] ?? $found->cancel_request_reason;

        if (! $cancelReason) {
            return response()->json(['message' => 'Debe indicar un motivo de cancelación.'], 422);
        }

        if ($found->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'Esta venta ya está cancelada.'], 422);
        }

        $wasCompleted = $found->status === SaleStatus::Completed;

        DB::transaction(function () use ($found, $user, $cancelReason, $wasCompleted) {
            $found->payments()->delete();

            $found->update([
                'status' => SaleStatus::Cancelled,
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $cancelReason,
            ]);

            if ($wasCompleted) {
                app(RecalculateClosedShifts::class)->forSale($found);
            }
        });

        $this->broadcast($found);

        return response()->json([
            'ok' => true,
            'recalculated_shifts' => $wasCompleted,
        ]);
    }

    public function reject(Request $request, int $sale): JsonResponse
    {
        $this->ensureAdmin($request);
        $found = $this->findSale($request, $sale);

        $found->update([
            'cancel_requested_at' => null,
            'cancel_requested_by' => null,
            'cancel_request_reason' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    private function ensureAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede gestionar cancelaciones.'
        );
    }

    /** Venta de la sucursal del token; cross-branch → 404. */
    private function findSale(Request $request, int $sale): Sale
    {
        return Sale::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->findOrFail($sale);
    }

    private function broadcast(Sale $sale): void
    {
        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }
    }
}
