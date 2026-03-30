<?php

namespace App\Http\Controllers\Caja;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        $sales = collect();

        if ($shift) {
            $sales = Sale::where('branch_id', $user->branch_id)
                ->where('user_id', $user->id)
                ->where('status', SaleStatus::Completed)
                ->where('completed_at', '>=', $shift->opened_at)
                ->orderByDesc('completed_at')
                ->get()
                ->map(fn (Sale $s) => [
                    'id' => $s->id,
                    'folio' => $s->folio,
                    'payment_method' => $s->payment_method,
                    'total' => (float) $s->total,
                    'completed_at' => $s->completed_at->toIso8601String(),
                ]);
        }

        $totals = [
            'total_cash' => (float) $sales->where('payment_method', 'cash')->sum('total'),
            'total_card' => (float) $sales->where('payment_method', 'card')->sum('total'),
            'total_transfer' => (float) $sales->where('payment_method', 'transfer')->sum('total'),
            'total_sales' => (float) $sales->sum('total'),
            'sale_count' => $sales->count(),
            'average' => $sales->count() > 0 ? round($sales->avg('total'), 2) : 0,
        ];

        return Inertia::render('Caja/Dashboard', [
            'totals' => $totals,
            'recentSales' => $sales->take(10)->values(),
            'shiftOpened' => $shift?->opened_at?->toIso8601String(),
            'tenant' => app('tenant'),
        ]);
    }
}
