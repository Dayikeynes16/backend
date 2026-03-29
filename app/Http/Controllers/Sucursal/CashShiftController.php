<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CashShiftController extends Controller
{
    public function active(): Response|RedirectResponse
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            return Inertia::render('Sucursal/Turno/Open', [
                'tenant' => app('tenant'),
            ]);
        }

        // Calculate live totals for this shift
        $payments = Payment::where('user_id', $user->id)
            ->where('created_at', '>=', $shift->opened_at)
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $expected = (float) $shift->opening_amount + $totalCash - $totalWithdrawals;

        return Inertia::render('Sucursal/Turno/Active', [
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
            return redirect()->route('sucursal.turno.active', app('tenant')->slug);
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

        return redirect()->route('sucursal.turno.active', app('tenant')->slug)
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
        ]);

        // Calculate totals from payments made by this user during this shift
        $payments = Payment::where('user_id', $user->id)
            ->where('created_at', '>=', $shift->opened_at)
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $expected = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
        $declared = round((float) $validated['declared_amount'], 2);
        $difference = round($declared - $expected, 2);

        $shift->update([
            'closed_at' => now(),
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $payments->pluck('sale_id')->unique()->count(),
            'declared_amount' => $declared,
            'expected_amount' => $expected,
            'difference' => $difference,
        ]);

        return redirect()->route('sucursal.turno.active', app('tenant')->slug)
            ->with('success', 'Turno cerrado. Diferencia: $' . number_format($difference, 2));
    }

    public function history(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $isAdmin = $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin');

        $shifts = CashRegisterShift::where('branch_id', $branchId)
            ->whereNotNull('closed_at')
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->with('user:id,name')
            ->when($request->date, fn ($q, $d) => $q->whereDate('opened_at', $d))
            ->orderByDesc('closed_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Sucursal/Cortes/Index', [
            'shifts' => $shifts,
            'filters' => $request->only('date'),
            'tenant' => app('tenant'),
            'isAdmin' => $isAdmin,
        ]);
    }

    public function recalculate(CashRegisterShift $shift): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizeAdmin($user, $shift);

        if (! $shift->closed_at) {
            return back()->with('error', 'Solo se pueden recalcular turnos cerrados.');
        }

        $payments = Payment::where('user_id', $shift->user_id)
            ->where('created_at', '>=', $shift->opened_at)
            ->when($shift->closed_at, fn ($q) => $q->where('created_at', '<=', $shift->closed_at))
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $expected = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
        $declared = (float) $shift->declared_amount;
        $difference = round($declared - $expected, 2);

        $shift->update([
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $payments->pluck('sale_id')->unique()->count(),
            'expected_amount' => $expected,
            'difference' => $difference,
        ]);

        return back()->with('success', 'Corte recalculado. Nueva diferencia: $' . number_format($difference, 2));
    }

    public function reopen(CashRegisterShift $shift): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizeAdmin($user, $shift);

        if (! $shift->closed_at) {
            return back()->with('error', 'Este turno ya esta abierto.');
        }

        // Ensure the shift owner doesn't have another open shift
        $hasOpenShift = CashRegisterShift::where('user_id', $shift->user_id)
            ->whereNull('closed_at')
            ->exists();

        if ($hasOpenShift) {
            return back()->with('error', 'El cajero ya tiene un turno abierto. Debe cerrarlo primero.');
        }

        $shift->update([
            'closed_at' => null,
            'total_cash' => null,
            'total_card' => null,
            'total_transfer' => null,
            'total_sales' => null,
            'sale_count' => null,
            'declared_amount' => null,
            'expected_amount' => null,
            'difference' => null,
        ]);

        return redirect()->route('sucursal.cortes.index', app('tenant')->slug)
            ->with('success', 'Turno reabierto. El cajero puede continuar operando.');
    }

    private function authorizeAdmin($user, CashRegisterShift $shift): void
    {
        if ($shift->branch_id !== $user->branch_id) {
            abort(403, 'Este corte no pertenece a tu sucursal.');
        }

        if ($shift->tenant_id !== $user->tenant_id) {
            abort(403, 'Este corte no pertenece a tu empresa.');
        }

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para esta accion.');
        }
    }

    public function show(CashRegisterShift $shift): Response
    {
        $user = Auth::user();

        // Validate shift belongs to this branch and tenant
        if ($shift->branch_id !== $user->branch_id) {
            abort(403, 'Este corte no pertenece a tu sucursal.');
        }

        if ($shift->tenant_id !== $user->tenant_id) {
            abort(403, 'Este corte no pertenece a tu empresa.');
        }

        $isAdmin = $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin');

        if (! $isAdmin && $shift->user_id !== $user->id) {
            abort(403);
        }

        $shift->load(['user:id,name', 'withdrawals']);

        return Inertia::render('Sucursal/Cortes/Show', [
            'shift' => $shift,
            'tenant' => app('tenant'),
            'isAdmin' => $isAdmin,
        ]);
    }
}
