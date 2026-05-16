<?php

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateSaleItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && (
            $user->hasRole('admin-sucursal')
            || $user->hasRole('admin-empresa')
            || $user->hasRole('superadmin')
        );
    }

    public function rules(): array
    {
        return [
            'quantity' => 'required|numeric|gt:0',
            'unit_price' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $branchId = Auth::user()?->branch_id;
            $mode = Branch::withoutGlobalScopes()
                ->where('id', $branchId)
                ->value('sale_item_edit_reason_mode') ?? 'optional';

            if ($mode === 'required' && trim((string) $this->input('reason')) === '') {
                $validator->errors()->add('reason', 'Esta sucursal exige indicar un motivo del cambio.');
            }
        });
    }
}
