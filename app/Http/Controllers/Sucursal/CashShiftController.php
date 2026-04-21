<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CashShiftController extends Controller
{
    /**
     * Métodos de pago soportados por el modelo de cierre de turno.
     * El backend mantiene columnas fijas (cash/card/transfer) por historia,
     * pero la UI respeta lo habilitado por sucursal.
     */
    private const SUPPORTED_METHODS = ['cash', 'card', 'transfer'];

    /**
     * Resuelve los métodos de pago habilitados para una sucursal.
     * Si la configuración es NULL, se asume todos activos (compat).
     * @return array<int,string>
     */
    private function enabledMethodsFor(?int $branchId): array
    {
        if (! $branchId) {
            return self::SUPPORTED_METHODS;
        }

        $branch = Branch::find($branchId);
        $methods = $branch?->payment_methods_enabled;

        if (! is_array($methods) || empty($methods)) {
            return self::SUPPORTED_METHODS;
        }

        // Intersección con los soportados para protegernos contra slugs desconocidos.
        return array_values(array_intersect(self::SUPPORTED_METHODS, $methods));
    }

    public function active(): Response|RedirectResponse
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            return Inertia::render('Sucursal/Turno/Open', [
                'tenant' => app('tenant'),
                'paymentMethods' => $this->enabledMethodsFor($user->branch_id),
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
            'paymentMethods' => $this->enabledMethodsFor($user->branch_id),
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

        $payments = Payment::where('user_id', $user->id)
            ->where('created_at', '>=', $shift->opened_at)
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        // Métodos que requieren declaración: habilitados en la sucursal +
        // cualquier método con movimientos durante el turno (por si se desactivó
        // a mitad del turno no perdemos la conciliación de dinero que sí entró).
        $enabled = $this->enabledMethodsFor($user->branch_id);
        $withMovement = array_filter([
            'cash' => $totalCash > 0,
            'card' => $totalCard > 0,
            'transfer' => $totalTransfer > 0,
        ]);
        $effective = array_values(array_unique(array_merge($enabled, array_keys($withMovement))));

        // "Cash" siempre se exige: aunque no haya cobros en efectivo, siempre hay un
        // fondo inicial que conciliar. Protege el caso de tener solo tarjeta habilitada.
        if (! in_array('cash', $effective, true)) {
            $effective[] = 'cash';
        }

        $rules = ['notes' => 'nullable|string|max:500'];
        if (in_array('cash', $effective, true))     $rules['declared_amount']   = 'required|numeric|min:0';
        if (in_array('card', $effective, true))     $rules['declared_card']     = 'required|numeric|min:0';
        if (in_array('transfer', $effective, true)) $rules['declared_transfer'] = 'required|numeric|min:0';

        $validated = $request->validate($rules);

        $expectedCash = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);

        // Para métodos NO efectivos, se guarda NULL en declared_*/difference_* (significa "no aplica").
        $declaredCash = array_key_exists('declared_amount', $validated)
            ? round((float) $validated['declared_amount'], 2)
            : null;
        $declaredCard = array_key_exists('declared_card', $validated)
            ? round((float) $validated['declared_card'], 2)
            : null;
        $declaredTransfer = array_key_exists('declared_transfer', $validated)
            ? round((float) $validated['declared_transfer'], 2)
            : null;

        $diffCash = $declaredCash !== null ? round($declaredCash - $expectedCash, 2) : null;
        $diffCard = $declaredCard !== null ? round($declaredCard - $totalCard, 2) : null;
        $diffTransfer = $declaredTransfer !== null ? round($declaredTransfer - $totalTransfer, 2) : null;
        $totalDiff = round(($diffCash ?? 0) + ($diffCard ?? 0) + ($diffTransfer ?? 0), 2);

        $shift->update([
            'closed_at' => now(),
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $payments->pluck('sale_id')->unique()->count(),
            // declared_* puede ser NULL (columnas nullable) — null = "no aplica".
            'declared_amount' => $declaredCash,
            'declared_card' => $declaredCard,
            'declared_transfer' => $declaredTransfer,
            'expected_amount' => $expectedCash,
            // difference_* no es nullable en DB; se persiste 0 cuando no aplica.
            // El discriminador "no aplica vs cuadra" vive en declared_*.
            'difference' => $diffCash ?? 0,
            'difference_card' => $diffCard ?? 0,
            'difference_transfer' => $diffTransfer ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('sucursal.turno.active', app('tenant')->slug)
            ->with('success', 'Turno cerrado. Diferencia total: $' . number_format($totalDiff, 2));
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

        // Respetar lo declarado originalmente: si un método se declaró con NULL
        // (porque estaba desactivado al cierre) no lo "resucitamos" con 0.
        $declaredCash = $shift->declared_amount !== null ? (float) $shift->declared_amount : null;
        $declaredCard = $shift->declared_card !== null ? (float) $shift->declared_card : null;
        $declaredTransfer = $shift->declared_transfer !== null ? (float) $shift->declared_transfer : null;

        $diffCash = $declaredCash !== null ? round($declaredCash - $expected, 2) : null;
        $diffCard = $declaredCard !== null ? round($declaredCard - $totalCard, 2) : null;
        $diffTransfer = $declaredTransfer !== null ? round($declaredTransfer - $totalTransfer, 2) : null;

        $shift->update([
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $payments->pluck('sale_id')->unique()->count(),
            'expected_amount' => $expected,
            // difference_* no nullable; persistir 0 cuando no aplica.
            'difference' => $diffCash ?? 0,
            'difference_card' => $diffCard ?? 0,
            'difference_transfer' => $diffTransfer ?? 0,
        ]);

        $totalDiff = round(($diffCash ?? 0) + ($diffCard ?? 0) + ($diffTransfer ?? 0), 2);

        return back()->with('success', 'Corte recalculado. Diferencia total: $' . number_format($totalDiff, 2));
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

        // Reset a valores neutros. Columnas NOT NULL (total_*, sale_count,
        // expected_amount, difference_*) vuelven a 0. Las nullable (declared_*,
        // closed_at) a NULL. Al cerrar de nuevo se recalcula todo.
        $shift->update([
            'closed_at' => null,
            'total_cash' => 0,
            'total_card' => 0,
            'total_transfer' => 0,
            'total_sales' => 0,
            'sale_count' => 0,
            'declared_amount' => null,
            'declared_card' => null,
            'declared_transfer' => null,
            'expected_amount' => 0,
            'difference' => 0,
            'difference_card' => 0,
            'difference_transfer' => 0,
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
            'paymentMethods' => $this->enabledMethodsFor($shift->branch_id),
            'tenant' => app('tenant'),
            'isAdmin' => $isAdmin,
        ]);
    }
}
