<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class WorkbenchController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();

        $hasShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if (! $hasShift) {
            return redirect()->route('caja.turno', app('tenant')->slug);
        }

        $sales = Sale::where('branch_id', $user->branch_id)
            ->where('status', 'active')
            ->with(['items', 'payments'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return Inertia::render('Caja/Workbench', [
            'sales' => $sales,
            'tenant' => app('tenant'),
            'branchId' => $user->branch_id,
        ]);
    }
}
