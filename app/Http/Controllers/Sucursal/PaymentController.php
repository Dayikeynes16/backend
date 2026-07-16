<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\PaymentReceiptService;
use App\Services\SalePaymentService;
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

        $canAttach = (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required);

        $rules = [
            'method' => "required|in:{$allowedStr}",
            'amount' => 'required|numeric|gt:0',
        ];
        if ($canAttach) {
            $rules['receipts'] = 'nullable|array|max:'.PaymentReceiptService::MAX_PER_PAYMENT;
            $rules['receipts.*'] = [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', PaymentReceiptService::ALLOWED_MIMES),
                'max:'.(PaymentReceiptService::MAX_BYTES / 1024),
            ];
        }

        $validated = $request->validate($rules, [
            'method.in' => 'El metodo de pago seleccionado no esta habilitado para esta sucursal.',
            'receipts.max' => 'Máximo 3 comprobantes por pago.',
            'receipts.*.mimes' => 'Solo se permiten imágenes (jpg, png, webp) o PDF.',
            'receipts.*.max' => 'Cada archivo no puede superar 5 MB.',
        ]);

        // Solo transferencias llevan comprobante; required lo exige.
        $receiptFiles = $canAttach && $validated['method'] === 'transfer'
            ? ($request->file('receipts') ?? [])
            : [];
        if ($branch->payment_receipts_required && $validated['method'] === 'transfer' && $receiptFiles === []) {
            return back()->withErrors(['receipts' => 'Adjunta el comprobante de la transferencia.']);
        }

        $change = 0;

        DB::transaction(function () use ($sale, $user, $validated, $receiptFiles, &$change) {
            $actualPayment = min((float) $validated['amount'], (float) $sale->amount_pending);

            $payment = Payment::create([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'method' => $validated['method'],
                'amount' => round($actualPayment, 2),
            ]);

            if ($receiptFiles !== []) {
                app(PaymentReceiptService::class)->attach($payment, $receiptFiles, $user->id);
            }

            app(SalePaymentService::class)->recalculate($sale, $user);
            $change = round((float) $validated['amount'] - $actualPayment, 2);
        });

        $this->broadcastSaleUpdate($sale);

        $msg = $sale->amount_pending <= 0
            ? "Venta {$sale->folio} cobrada.".($change > 0 ? " Cambio: \${$change}" : '')
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
            app(SalePaymentService::class)->recalculate($sale, $user);
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
            foreach ($payment->receipts()->get() as $receipt) {
                app(PaymentReceiptService::class)->delete($receipt);
            }

            $payment->delete();
            app(SalePaymentService::class)->recalculate($sale, $user);
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
