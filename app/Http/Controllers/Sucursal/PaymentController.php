<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
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
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function store(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === SaleStatus::Completed || $sale->status === SaleStatus::Cancelled) {
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

        $this->broadcastSaleUpdate($sale);

        $msg = $sale->amount_pending <= 0
            ? "Venta {$sale->folio} cobrada." . ($change > 0 ? " Cambio: \${$change}" : '')
            : "Pago registrado. Pendiente: \${$sale->amount_pending}";

        return back()->with('success', $msg);
    }

    public function update(Request $request, Sale $sale, Payment $payment): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizePaymentAction($user, $sale, $payment);

        if ($sale->status === SaleStatus::Cancelled) {
            return back()->with('error', 'No se pueden modificar pagos de una venta cancelada.');
        }

        if ($payment->customer_payment_id !== null) {
            $payment->loadMissing('customerPayment:id,folio');
            $folio = $payment->customerPayment?->folio ?? 'global';
            return back()->with('error', "Este pago es parte del cobro {$folio} y no puede editarse individualmente.");
        }

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $allowed = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];
        $allowedStr = implode(',', $allowed);

        // Max allowed: sale total minus other payments (excluding this one being edited)
        $otherPaymentsTotal = $sale->payments()->where('id', '!=', $payment->id)->sum('amount');
        $maxAmount = round((float) $sale->total - $otherPaymentsTotal, 2);

        $validated = $request->validate([
            'method' => "required|in:{$allowedStr}",
            'amount' => "required|numeric|gt:0|max:{$maxAmount}",
        ], [
            'method.in' => 'El metodo de pago seleccionado no esta habilitado para esta sucursal.',
            'amount.max' => "El monto no puede exceder \${$maxAmount} (total de la venta menos otros pagos).",
        ]);

        DB::transaction(function () use ($payment, $sale, $user, $validated) {
            $payment->update(array_merge($validated, ['updated_by' => $user->id]));
            $this->recalculate($sale, $user);
        });

        $this->broadcastSaleUpdate($sale);

        return back()->with('success', 'Pago actualizado.');
    }

    public function destroy(Sale $sale, Payment $payment): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizePaymentAction($user, $sale, $payment);

        if ($sale->status === SaleStatus::Cancelled) {
            return back()->with('error', 'No se pueden modificar pagos de una venta cancelada.');
        }

        if ($payment->customer_payment_id !== null) {
            $payment->loadMissing('customerPayment:id,folio');
            $folio = $payment->customerPayment?->folio ?? 'global';
            return back()->with('error', "Este pago es parte del cobro {$folio} y no puede eliminarse individualmente.");
        }

        DB::transaction(function () use ($payment, $sale, $user) {
            $payment->delete();
            $this->recalculate($sale, $user);
        });

        $this->broadcastSaleUpdate($sale);

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

    /**
     * Recalculate sale totals and status based on current payments.
     * Must be called inside a DB transaction. Does NOT broadcast.
     */
    private function recalculate(Sale $sale, $user): void
    {
        $totalPaid = $sale->payments()->sum('amount');
        $pending = round((float) $sale->total - $totalPaid, 2);

        $data = [
            'amount_paid' => $totalPaid,
            'amount_pending' => max($pending, 0),
        ];

        if ($pending <= 0 && $totalPaid > 0) {
            $data['status'] = SaleStatus::Completed;
            $data['completed_at'] = now();
            $data['user_id'] = $user->id;
        } elseif ($totalPaid > 0) {
            if ($sale->status === SaleStatus::Completed) {
                $data['status'] = SaleStatus::Active;
                $data['completed_at'] = null;
            }
        } elseif ($totalPaid == 0 && $sale->status !== SaleStatus::Cancelled) {
            $data['status'] = SaleStatus::Active;
            $data['completed_at'] = null;
        }

        $sale->update($data);
    }

    /**
     * Broadcast sale update. Non-blocking: broadcast failures
     * are logged but never break the main operation.
     */
    private function broadcastSaleUpdate(Sale $sale): void
    {
        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
