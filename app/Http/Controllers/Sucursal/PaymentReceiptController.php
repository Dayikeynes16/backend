<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprobantes de pagos de venta. Compartido por los prefijos sucursal y
 * caja (mismo patrón que PaymentController). Los de cobro global viven en
 * CustomerPaymentReceiptController.
 */
class PaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceiptService $receipts) {}

    public function store(Request $request, Payment $payment): RedirectResponse
    {
        $user = Auth::user();
        $branch = $this->authorizeMutation($user, $payment);

        if ($payment->method !== 'transfer') {
            return back()->withErrors(['receipts' => 'Solo los pagos por transferencia llevan comprobante.']);
        }
        if ($payment->customer_payment_id !== null) {
            return back()->withErrors(['receipts' => 'El comprobante va en el cobro global.']);
        }

        $request->validate([
            'receipts' => 'required|array|max:'.PaymentReceiptService::MAX_PER_PAYMENT,
            'receipts.*' => [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', PaymentReceiptService::ALLOWED_MIMES),
                'max:'.(PaymentReceiptService::MAX_BYTES / 1024),
            ],
        ], [
            'receipts.max' => 'Máximo 3 comprobantes por pago.',
            'receipts.*.mimes' => 'Solo se permiten imágenes (jpg, png, webp) o PDF.',
            'receipts.*.max' => 'Cada archivo no puede superar 5 MB.',
        ]);

        $existing = $payment->receipts()->count();
        $incoming = count($request->file('receipts'));
        if ($existing + $incoming > PaymentReceiptService::MAX_PER_PAYMENT) {
            return back()->withErrors(['receipts' => 'Máximo 3 comprobantes por pago.']);
        }

        $this->receipts->attach($payment, $request->file('receipts'), $user->id);

        return back()->with('success', 'Comprobante adjuntado.');
    }

    public function download(Payment $payment, PaymentReceipt $receipt): StreamedResponse
    {
        $user = Auth::user();
        $this->authorizeView($user, $payment);
        abort_unless($receipt->payment_id === $payment->id, 404);

        return Storage::disk(PaymentReceiptService::disk())->download($receipt->path, $receipt->original_name);
    }

    public function destroy(Payment $payment, PaymentReceipt $receipt): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizeMutation($user, $payment);
        abort_unless($receipt->payment_id === $payment->id, 404);

        $this->receipts->delete($receipt);

        return back()->with('success', 'Comprobante eliminado.');
    }

    /** Flag encendido + pago de la sucursal del usuario. */
    private function authorizeView($user, Payment $payment): Branch
    {
        $payment->loadMissing('sale');
        abort_unless($payment->sale && $payment->sale->branch_id === $user->branch_id, 404);

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        abort_unless(
            $branch->payment_receipts_enabled || $branch->payment_receipts_required,
            403,
            'Tu empresa no ha habilitado esta función para tu sucursal.'
        );

        return $branch;
    }

    /**
     * Mutación: admin (sucursal/empresa/superadmin) cualquiera de su sucursal;
     * cajero solo pagos SUYOS dentro de su turno abierto (payments no tiene
     * shift_id: se deriva por user_id + created_at >= opened_at — decisión
     * fijada en el spec).
     */
    private function authorizeMutation($user, Payment $payment): Branch
    {
        $branch = $this->authorizeView($user, $payment);

        if ($user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin')) {
            return $branch;
        }

        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
        abort_unless(
            $shift && $payment->user_id === $user->id && $payment->created_at >= $shift->opened_at,
            403,
            'Solo puedes modificar comprobantes de tus pagos del turno abierto.'
        );

        return $branch;
    }
}
