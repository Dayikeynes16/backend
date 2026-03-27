<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        $shifts = CashRegisterShift::where('branch_id', $branchId)
            ->with('user:id,name')
            ->whereNotNull('closed_at')
            ->when($request->date, fn ($q, $d) => $q->whereDate('opened_at', $d))
            ->orderByDesc('closed_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Sucursal/Cortes/Index', [
            'shifts' => $shifts,
            'filters' => $request->only('date'),
            'tenant' => app('tenant'),
        ]);
    }
}
