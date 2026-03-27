<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();

        // Require open shift
        $hasShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if (! $hasShift) {
            return redirect()->route('caja.shift.create', app('tenant')->slug);
        }

        $pendingSales = Sale::where('branch_id', $user->branch_id)
            ->where('status', 'pending')
            ->with('items')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'folio' => $sale->folio,
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'total' => (float) $sale->total,
                'created_at' => $sale->created_at->toIso8601String(),
                'items' => $sale->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'unit_type' => $item->unit_type,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ]),
            ]);

        return Inertia::render('Caja/Queue', [
            'pendingSales' => $pendingSales,
            'branchId' => $user->branch_id,
            'tenant' => app('tenant'),
        ]);
    }

    public function complete(Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status !== 'pending') {
            return back()->with('error', 'Esta venta ya fue procesada.');
        }

        $sale->update([
            'status' => 'completed',
            'user_id' => $user->id,
            'completed_at' => now(),
        ]);

        return back()->with('success', "Venta {$sale->folio} cobrada.");
    }
}
