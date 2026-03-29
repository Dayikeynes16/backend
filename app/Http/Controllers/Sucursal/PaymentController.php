<?php

namespace App\Http\Controllers\Sucursal;

use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        // Require open shift to register payments
        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if (! $hasOpenShift) {
            return back()->with('error', 'Debes tener un turno abierto para registrar pagos.');
        }

        // Concurrency guard: if locked by someone else
        if ($sale->isLockedBy(null) && $sale->locked_by !== $user->id) {
            return back()->with('error', 'Esta venta esta siendo operada por otro usuario.');
        }

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $allowed = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];
        $allowedStr = implode(',', $allowed);

        $validated = $request->validate([
            'method' => "required|in:{$allowedStr}",
            'amount' => 'required|numeric|gt:0',
        ], [
            'method.in' => 'El metodo de pago seleccionado no esta habilitado para esta sucursal.',
        ]);

        $change = 0;

        DB::transaction(function () use ($sale, $user, $validated, &$change) {
            $actualPayment = min((float) $validated['amount'], (float) $sale->amount_pending);

            Payment::create([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'method' => $validated['method'],
                'amount' => round($actualPayment, 2),
            ]);

            $this->recalculate($sale, $user);
            $change = round((float) $validated['amount'] - $actualPayment, 2);
        });

        $msg = $sale->amount_pending <= 0
            ? "Venta {$sale->folio} cobrada." . ($change > 0 ? " Cambio: \${$change}" : '')
            : "Pago registrado. Pendiente: \${$sale->amount_pending}";

        return back()->with('success', $msg);
    }

    public function update(Request $request, Sale $sale, Payment $payment): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizePaymentAction($user, $sale, $payment);

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $allowed = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];
        $allowedStr = implode(',', $allowed);

        $validated = $request->validate([
            'method' => "required|in:{$allowedStr}",
            'amount' => 'required|numeric|gt:0',
        ], [
            'method.in' => 'El metodo de pago seleccionado no esta habilitado para esta sucursal.',
        ]);

        DB::transaction(function () use ($payment, $sale, $user, $validated) {
            $payment->update(array_merge($validated, ['updated_by' => $user->id]));
            $this->recalculate($sale, $user);
        });

        return back()->with('success', 'Pago actualizado.');
    }

    public function destroy(Sale $sale, Payment $payment): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizePaymentAction($user, $sale, $payment);

        DB::transaction(function () use ($payment, $sale, $user) {
            $payment->delete();
            $this->recalculate($sale, $user);
        });

        return back()->with('success', 'Pago eliminado.');
    }

    private function authorizePaymentAction($user, Sale $sale, Payment $payment): void
    {
        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($payment->sale_id !== $sale->id) {
            abort(403);
        }

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para modificar pagos.');
        }
    }

    private function recalculate(Sale $sale, $user): void
    {
        $totalPaid = $sale->payments()->sum('amount');
        $pending = round((float) $sale->total - $totalPaid, 2);

        $data = [
            'amount_paid' => $totalPaid,
            'amount_pending' => max($pending, 0),
        ];

        if ($pending <= 0 && $totalPaid > 0) {
            $data['status'] = 'completed';
            $data['completed_at'] = now();
            $data['user_id'] = $user->id;
        } elseif ($totalPaid > 0) {
            if ($sale->status === 'completed') {
                $data['status'] = 'active';
                $data['completed_at'] = null;
            }
        } elseif ($totalPaid == 0 && $sale->status !== 'cancelled') {
            $data['status'] = 'active';
            $data['completed_at'] = null;
        }

        $sale->update($data);

        SaleUpdated::dispatch($sale->fresh());
    }
}
