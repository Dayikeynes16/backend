<?php

namespace App\Http\Controllers\Caja;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Alta de gasto en efectivo desde la caja, ligado al turno abierto del cajero.
 * Sale del cajón → descuenta del efectivo esperado del corte.
 */
class GastoController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            abort(422, 'Abre tu turno antes de registrar un gasto.');
        }

        $validated = $request->validate([
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('status', 'active')),
            ],
            'description' => 'nullable|string|max:1000',
        ], [
            'expense_subcategory_id.required' => 'Selecciona una subcategoría.',
            'expense_subcategory_id.exists' => 'La subcategoría no es válida o está inactiva.',
            'amount.min' => 'El monto debe ser mayor a 0.',
        ]);

        Expense::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $shift->branch_id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'user_id' => $user->id,
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'payment_method' => PaymentMethod::Cash->value,
            'expense_at' => now(),
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('success', 'Gasto en efectivo registrado.');
    }
}
