<?php

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class RegisterCustomerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (! $user) return false;

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
        ];
    }

    public function messages(): array
    {
        return [
            'method.in' => 'El metodo de pago seleccionado no esta habilitado para esta sucursal.',
            'amount_received.gt' => 'El monto debe ser mayor a cero.',
        ];
    }
}
