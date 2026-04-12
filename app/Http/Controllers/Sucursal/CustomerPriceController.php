<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerPriceController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $user = Auth::user();

        if ($customer->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'price' => 'required|numeric|min:0',
        ]);

        $exists = $customer->prices()
            ->where('product_id', $validated['product_id'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['product_id' => 'Este producto ya tiene un precio asignado.']);
        }

        $customer->prices()->create($validated);

        return back()->with('success', 'Precio preferencial asignado.');
    }

    public function update(Request $request, Customer $customer, CustomerProductPrice $price): RedirectResponse
    {
        $user = Auth::user();

        if ($customer->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($price->customer_id !== $customer->id) {
            abort(403);
        }

        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);

        $price->update($validated);

        return back()->with('success', 'Precio actualizado.');
    }

    public function destroy(Customer $customer, CustomerProductPrice $price): RedirectResponse
    {
        $user = Auth::user();

        if ($customer->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($price->customer_id !== $customer->id) {
            abort(403);
        }

        $price->delete();

        return back()->with('success', 'Precio preferencial eliminado.');
    }
}
