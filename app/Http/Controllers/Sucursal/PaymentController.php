<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    public function store(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === 'completed' || $sale->status === 'cancelled') {
            return back()->with('error', 'No se pueden registrar pagos en esta venta.');
        }

        $validated = $request->validate([
            'method' => 'required|in:cash,card,transfer',
            'amount' => 'required|numeric|gt:0|max:' . $sale->amount_pending,
        ]);

        Payment::create([
            'sale_id' => $sale->id,
            'method' => $validated['method'],
            'amount' => $validated['amount'],
        ]);

        $totalPaid = $sale->payments()->sum('amount');
        $pending = round((float) $sale->total - $totalPaid, 2);

        $updateData = [
            'amount_paid' => $totalPaid,
            'amount_pending' => max($pending, 0),
        ];

        if ($pending <= 0) {
            $updateData['status'] = 'completed';
            $updateData['completed_at'] = now();
            $updateData['user_id'] = $user->id;
        } elseif ($totalPaid > 0 && $sale->status === 'active') {
            $updateData['status'] = 'pending';
        }

        $sale->update($updateData);

        $msg = $pending <= 0 ? "Venta {$sale->folio} cobrada completamente." : "Pago registrado. Pendiente: \${$pending}";

        return back()->with('success', $msg);
    }
}
