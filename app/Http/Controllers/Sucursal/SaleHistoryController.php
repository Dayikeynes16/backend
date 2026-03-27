<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SaleHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        $sales = Sale::where('branch_id', $branchId)
            ->with(['items', 'payments.user:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where('folio', 'ilike', "%{$s}%"))
            ->when($request->date, fn ($q, $d) => $q->whereDate('created_at', $d))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sucursal/Historial/Index', [
            'sales' => $sales,
            'filters' => $request->only('status', 'search', 'date'),
            'tenant' => app('tenant'),
        ]);
    }
}
