<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Caja/OpenShift', [
            'tenant' => app('tenant'),
        ]);
    }

    public function store(): RedirectResponse
    {
        $user = Auth::user();

        // Don't allow multiple open shifts
        $existing = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if ($existing) {
            return redirect()->route('caja.queue', app('tenant')->slug);
        }

        CashRegisterShift::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now(),
        ]);

        return redirect()->route('caja.queue', app('tenant')->slug);
    }

    public function show(): Response
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $sales = Sale::where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $shift->opened_at)
            ->get();

        $totals = [
            'total_cash' => (float) $sales->where('payment_method', 'cash')->sum('total'),
            'total_card' => (float) $sales->where('payment_method', 'card')->sum('total'),
            'total_transfer' => (float) $sales->where('payment_method', 'transfer')->sum('total'),
            'total_sales' => (float) $sales->sum('total'),
            'sale_count' => $sales->count(),
            'average' => $sales->count() > 0 ? round((float) $sales->avg('total'), 2) : 0,
        ];

        return Inertia::render('Caja/Shift', [
            'shift' => [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at->toIso8601String(),
            ],
            'totals' => $totals,
            'tenant' => app('tenant'),
        ]);
    }

    public function close(): RedirectResponse
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $sales = Sale::where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $shift->opened_at)
            ->get();

        $shift->update([
            'closed_at' => now(),
            'total_cash' => $sales->where('payment_method', 'cash')->sum('total'),
            'total_card' => $sales->where('payment_method', 'card')->sum('total'),
            'total_transfer' => $sales->where('payment_method', 'transfer')->sum('total'),
            'total_sales' => $sales->sum('total'),
            'sale_count' => $sales->count(),
        ]);

        return redirect()->route('caja.shift.create', app('tenant')->slug)
            ->with('success', 'Turno cerrado exitosamente.');
    }
}
