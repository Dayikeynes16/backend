<?php

namespace App\Http\Controllers\Sucursal;

use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CancelRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        // Pending requests (existing)
        $requests = Sale::where('branch_id', $branchId)
            ->whereNotNull('cancel_requested_at')
            ->whereNull('cancelled_at')
            ->where('status', '!=', 'cancelled')
            ->with(['items', 'cancelRequestedByUser:id,name'])
            ->orderByDesc('cancel_requested_at')
            ->get();

        // Date filter for stats/history (default: today)
        $date = $request->input('date', now()->toDateString());

        // Cancelled sales for the selected date
        $cancelledQuery = Sale::where('branch_id', $branchId)
            ->where('status', 'cancelled')
            ->whereDate('cancelled_at', $date);

        $cancelledToday = (clone $cancelledQuery)->count();
        $cancelledTotal = (clone $cancelledQuery)->sum('total');

        // Top cancellation reasons (last 30 days)
        $topReasons = Sale::where('branch_id', $branchId)
            ->where('status', 'cancelled')
            ->whereNotNull('cancel_reason')
            ->where('cancelled_at', '>=', now()->subDays(30))
            ->select('cancel_reason', DB::raw('count(*) as count'), DB::raw('sum(total) as total'))
            ->groupBy('cancel_reason')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // Recent cancelled sales history for the selected date
        $history = Sale::where('branch_id', $branchId)
            ->where('status', 'cancelled')
            ->whereDate('cancelled_at', $date)
            ->with(['cancelledByUser:id,name', 'cancelRequestedByUser:id,name', 'items'])
            ->orderByDesc('cancelled_at')
            ->get();

        return Inertia::render('Sucursal/Cancelaciones/Index', [
            'requests' => $requests,
            'stats' => [
                'cancelled_count' => $cancelledToday,
                'cancelled_total' => round((float) $cancelledTotal, 2),
                'date' => $date,
            ],
            'topReasons' => $topReasons,
            'history' => $history,
            'filters' => ['date' => $date],
            'tenant' => app('tenant'),
        ]);
    }

    public function approve(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $wasCompleted = $sale->status === 'completed';

        DB::transaction(function () use ($sale, $user, $validated, $wasCompleted) {
            $sale->payments()->delete();

            $sale->update([
                'status' => 'cancelled',
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $validated['cancel_reason'],
            ]);

            if ($wasCompleted) {
                $this->recalculateAffectedShifts($sale);
            }
        });

        SaleUpdated::dispatch($sale->fresh());

        $msg = "Venta {$sale->folio} cancelada.";
        if ($wasCompleted) {
            $msg .= ' Los cortes de caja fueron recalculados.';
        }

        return back()->with('success', $msg);
    }

    private function recalculateAffectedShifts(Sale $sale): void
    {
        $payments = Payment::withTrashed()
            ->where('sale_id', $sale->id)
            ->get();

        $affectedUserIds = $payments->pluck('user_id')->unique();

        foreach ($affectedUserIds as $userId) {
            $userPayments = $payments->where('user_id', $userId);
            $earliest = $userPayments->min('created_at');

            $shifts = CashRegisterShift::where('user_id', $userId)
                ->whereNotNull('closed_at')
                ->where('opened_at', '<=', $earliest)
                ->get();

            foreach ($shifts as $shift) {
                $shiftPayments = Payment::where('user_id', $shift->user_id)
                    ->where('created_at', '>=', $shift->opened_at)
                    ->where('created_at', '<=', $shift->closed_at)
                    ->get();

                $totalCash = (float) $shiftPayments->where('method', 'cash')->sum('amount');
                $totalCard = (float) $shiftPayments->where('method', 'card')->sum('amount');
                $totalTransfer = (float) $shiftPayments->where('method', 'transfer')->sum('amount');
                $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

                $expected = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
                $declared = (float) $shift->declared_amount;

                $shift->update([
                    'total_cash' => $totalCash,
                    'total_card' => $totalCard,
                    'total_transfer' => $totalTransfer,
                    'total_sales' => $totalCash + $totalCard + $totalTransfer,
                    'sale_count' => $shiftPayments->pluck('sale_id')->unique()->count(),
                    'expected_amount' => $expected,
                    'difference' => round($declared - $expected, 2),
                ]);
            }
        }
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
