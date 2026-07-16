<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class RegisterCustomerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return $user->hasRole('admin-sucursal')
            || $user->hasRole('admin-empresa')
            || $user->hasRole('superadmin');
    }

    public function rules(): array
    {
        $user = Auth::user();
        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);
        $allowed = $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'];
        $allowedStr = implode(',', $allowed);

        return [
            'amount_received' => 'required|numeric|gt:0',
            'method' => "required|string|in:{$allowedStr}",
            'excluded_sale_ids' => 'nullable|array',
            'excluded_sale_ids.*' => 'integer|min:1',
            'notes' => 'nullable|string|max:500',
            // Reglas siempre presentes; el "solo con flag habilitado" se decide
            // en el controlador porque el Request no conoce el branch a tiempo.
            'receipts' => 'nullable|array|max:'.PaymentReceiptService::MAX_PER_PAYMENT,
            'receipts.*' => [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', PaymentReceiptService::ALLOWED_MIMES),
                'max:'.(PaymentReceiptService::MAX_BYTES / 1024),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'method.in' => 'El metodo de pago seleccionado no esta habilitado para esta sucursal.',
            'amount_received.gt' => 'El monto debe ser mayor a cero.',
            'receipts.max' => 'Máximo 3 comprobantes por pago.',
            'receipts.*.mimes' => 'Solo se permiten imágenes (jpg, png, webp) o PDF.',
            'receipts.*.max' => 'Cada archivo no puede superar 5 MB.',
        ];
    }
}
