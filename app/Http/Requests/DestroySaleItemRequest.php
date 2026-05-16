<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class DestroySaleItemRequest extends FormRequest
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
        // Motivo obligatorio siempre al eliminar, regla dura de negocio.
        // No depende de branches.sale_item_edit_reason_mode.
        return [
            'reason' => 'required|string|max:500|min:1',
        ];
    }
}
