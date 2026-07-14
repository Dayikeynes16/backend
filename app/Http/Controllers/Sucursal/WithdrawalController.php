<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\CashWithdrawal;
use App\Services\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WithdrawalController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:255',
        ]);

        $this->shifts->addWithdrawal(Auth::user(), (float) $validated['amount'], $validated['reason']);

        return back()->with('success', "Retiro de \${$validated['amount']} registrado.");
    }

    public function destroy(CashWithdrawal $withdrawal): RedirectResponse
    {
        $this->shifts->removeWithdrawal(Auth::user(), $withdrawal);

        return back()->with('success', 'Retiro eliminado.');
    }
}
