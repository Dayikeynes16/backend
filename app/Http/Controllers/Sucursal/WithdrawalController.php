<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WithdrawalController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:255',
        ]);

        CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'created_at' => now(),
        ]);

        return back()->with('success', "Retiro de \${$validated['amount']} registrado.");
    }

    public function destroy(CashWithdrawal $withdrawal): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403);
        }

        // Validate ownership: withdrawal → shift → branch must match current user
        $shift = $withdrawal->shift;

        if (! $shift || $shift->branch_id !== $user->branch_id) {
            abort(403, 'Este retiro no pertenece a tu sucursal.');
        }

        if ($shift->tenant_id !== $user->tenant_id) {
            abort(403, 'Este retiro no pertenece a tu empresa.');
        }

        $withdrawal->delete();

        return back()->with('success', 'Retiro eliminado.');
    }
}
