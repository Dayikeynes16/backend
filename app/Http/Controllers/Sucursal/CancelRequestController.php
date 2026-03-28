<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CancelRequestController extends Controller
{
    public function index(): Response
    {
        $branchId = Auth::user()->branch_id;

        $requests = Sale::where('branch_id', $branchId)
            ->whereNotNull('cancel_requested_at')
            ->whereNull('cancelled_at')
            ->where('status', '!=', 'cancelled')
            ->with(['items', 'cancelRequestedByUser:id,name'])
            ->orderByDesc('cancel_requested_at')
            ->get();

        return Inertia::render('Sucursal/Cancelaciones/Index', [
            'requests' => $requests,
            'tenant' => app('tenant'),
        ]);
    }

    public function approve(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($sale, $user, $validated) {
            $sale->payments()->delete();

            $sale->update([
                'status' => 'cancelled',
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $validated['cancel_reason'],
            ]);
        });

        return back()->with('success', "Venta {$sale->folio} cancelada.");
    }

    public function reject(Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $sale->update([
            'cancel_requested_at' => null,
            'cancel_requested_by' => null,
            'cancel_request_reason' => null,
        ]);

        return back()->with('success', "Solicitud de cancelacion rechazada para {$sale->folio}.");
    }
}
