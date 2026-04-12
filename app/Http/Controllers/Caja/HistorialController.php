<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class HistorialController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // Sales where this cajero registered at least one payment
        $saleIds = Payment::where('user_id', $user->id)
            ->distinct()
            ->pluck('sale_id');

        $sales = Sale::whereIn('id', $saleIds)
            ->with(['items', 'payments'])
            ->when(
                $request->date,
                fn ($q, $d) => $q->whereDate('created_at', $d),
                fn ($q) => $q->whereDate('created_at', today())
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(20)
            ->withQueryString();

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);

        return Inertia::render('Caja/Historial', [
            'sales' => $sales,
            'filters' => $request->only('date'),
            'tenant' => app('tenant'),
            'branchInfo' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'ticket_config' => $branch->ticket_config,
            ],
        ]);
    }
}
