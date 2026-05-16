<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreSaleItemRequest extends FormRequest
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
        $tenantId = $this->route('sale') instanceof Sale
            ? $this->route('sale')->tenant_id
            : null;

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'presentation_id' => 'nullable|integer|exists:product_presentations,id',
            'quantity' => 'required|numeric|gt:0',
            'unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Si la sucursal exige motivo obligatorio para add/update, lo validamos
            // aquí (la regla "required" depende de runtime). En remove() esto se
            // valida en el servicio, regla dura sin importar la config.
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
