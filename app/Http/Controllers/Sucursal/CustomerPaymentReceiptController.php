<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\CustomerPayment;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprobantes de cobros globales de fiado (CustomerPayment). Compartido por
 * los prefijos sucursal y caja (mismo patrón que PaymentReceiptController,
 * del cual es espejo). Los de pagos de venta viven en PaymentReceiptController.
 */
class CustomerPaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceiptService $receipts) {}

    public function store(Request $request, CustomerPayment $customerPayment): RedirectResponse
    {
        $user = Auth::user();
        $branch = $this->authorizeMutation($user, $customerPayment);

        if ($customerPayment->method !== 'transfer') {
            return back()->withErrors(['receipts' => 'Solo los pagos por transferencia llevan comprobante.']);
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

        $existing = $customerPayment->receipts()->count();
        $incoming = count($request->file('receipts'));
        if ($existing + $incoming > PaymentReceiptService::MAX_PER_PAYMENT) {
            return back()->withErrors(['receipts' => 'Máximo 3 comprobantes por pago.']);
        }

        $this->receipts->attach($customerPayment, $request->file('receipts'), $user->id);

        return back()->with('success', 'Comprobante adjuntado.');
    }

    public function download(CustomerPayment $customerPayment, PaymentReceipt $receipt): StreamedResponse
    {
        $user = Auth::user();
        $this->authorizeView($user, $customerPayment);
        abort_unless($receipt->customer_payment_id === $customerPayment->id, 404);

        return Storage::disk(PaymentReceiptService::disk())->download($receipt->path, $receipt->original_name);
    }

    public function destroy(CustomerPayment $customerPayment, PaymentReceipt $receipt): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizeMutation($user, $customerPayment);
        abort_unless($receipt->customer_payment_id === $customerPayment->id, 404);

        $this->receipts->delete($receipt);

        return back()->with('success', 'Comprobante eliminado.');
    }

    /** Flag encendido + cobro global de la sucursal del usuario. */
    private function authorizeView($user, CustomerPayment $customerPayment): Branch
    {
        abort_unless($customerPayment->branch_id === $user->branch_id, 404);

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
     * cajero solo cobros globales SUYOS dentro de su turno abierto
     * (customer_payments no tiene shift_id: se deriva por user_id +
     * created_at >= opened_at — decisión fijada en el spec).
     */
    private function authorizeMutation($user, CustomerPayment $customerPayment): Branch
    {
        $branch = $this->authorizeView($user, $customerPayment);

        if ($user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin')) {
            return $branch;
        }

        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
        abort_unless(
            $shift && $customerPayment->user_id === $user->id && $customerPayment->created_at >= $shift->opened_at,
            403,
            'Solo puedes modificar comprobantes de tus pagos del turno abierto.'
        );

        return $branch;
    }
}
