<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TurnoController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            return Inertia::render('Caja/Turno/Open', [
                'tenant' => app('tenant'),
            ]);
        }

        $payments = Payment::where('user_id', $user->id)
            ->where('created_at', '>=', $shift->opened_at)
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
        $expected = (float) $shift->opening_amount + $totalCash - $totalWithdrawals;

        return Inertia::render('Caja/Turno/Active', [
            'shift' => $shift->load('withdrawals'),
            'totals' => [
                'cash' => $totalCash,
                'card' => $totalCard,
                'transfer' => $totalTransfer,
                'total' => $totalCash + $totalCard + $totalTransfer,
                'withdrawals' => $totalWithdrawals,
                'expected_cash' => round($expected, 2),
                'payment_count' => $payments->pluck('sale_id')->unique()->count(),
            ],
            'tenant' => app('tenant'),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $existing = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if ($existing) {
            return redirect()->route('caja.workbench', app('tenant')->slug);
        }

        $validated = $request->validate([
            'opening_amount' => 'nullable|numeric|min:0',
        ]);

        CashRegisterShift::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => $validated['opening_amount'] ?? 0,
        ]);

        return redirect()->route('caja.workbench', app('tenant')->slug)
            ->with('success', 'Turno abierto.');
    }

    public function close(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $validated = $request->validate([
            'declared_amount' => 'required|numeric|min:0',
            'declared_card' => 'required|numeric|min:0',
            'declared_transfer' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $payments = Payment::where('user_id', $user->id)
            ->where('created_at', '>=', $shift->opened_at)
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $expectedCash = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
        $declaredCash = round((float) $validated['declared_amount'], 2);
        $declaredCard = round((float) $validated['declared_card'], 2);
        $declaredTransfer = round((float) $validated['declared_transfer'], 2);

        $shift->update([
            'closed_at' => now(),
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $payments->pluck('sale_id')->unique()->count(),
            'declared_amount' => $declaredCash,
            'declared_card' => $declaredCard,
            'declared_transfer' => $declaredTransfer,
            'expected_amount' => $expectedCash,
            'difference' => round($declaredCash - $expectedCash, 2),
            'difference_card' => round($declaredCard - $totalCard, 2),
            'difference_transfer' => round($declaredTransfer - $totalTransfer, 2),
            'notes' => $validated['notes'],
        ]);

        return redirect()->route('caja.turno', app('tenant')->slug)
            ->with('success', 'Turno cerrado.');
    }
}
