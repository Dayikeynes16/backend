<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Precios preferenciales por producto de un cliente (admin-sucursal/caja desde
 * el hub). El precio se interpreta como $/unidad base del producto (igual que la
 * web). Un cliente no puede tener dos precios para el mismo producto.
 */
class CustomerPriceController extends Controller
{
    public function store(Request $request, int $customer): JsonResponse
    {
        $found = $this->findCustomer($request, $customer);

        $validated = $request->validate([
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $found->tenant_id)),
                Rule::unique('customer_product_prices', 'product_id')->where(fn ($q) => $q->where('customer_id', $found->id)),
            ],
            'price' => 'required|numeric|min:0',
        ], [
            'product_id.unique' => 'Ya existe un precio preferencial para ese producto.',
        ]);

        $price = CustomerProductPrice::create([
            'customer_id' => $found->id,
            'product_id' => $validated['product_id'],
            'price' => $validated['price'],
        ]);

        return response()->json(['data' => $this->row($price)], 201);
    }

    public function update(Request $request, int $customer, int $price): JsonResponse
    {
        $found = $this->findCustomer($request, $customer);
        $model = CustomerProductPrice::where('customer_id', $found->id)->findOrFail($price);

        $validated = $request->validate(['price' => 'required|numeric|min:0']);
        $model->update(['price' => $validated['price']]);

        return response()->json(['data' => $this->row($model)]);
    }

    public function destroy(Request $request, int $customer, int $price): JsonResponse
    {
        $found = $this->findCustomer($request, $customer);
        $model = CustomerProductPrice::where('customer_id', $found->id)->findOrFail($price);
        $model->delete();

        return response()->json(['action' => 'deleted']);
    }

    private function findCustomer(Request $request, int $customer): Customer
    {
        return Customer::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->findOrFail($customer);
    }

    private function row(CustomerProductPrice $price): array
    {
        $product = Product::withoutGlobalScopes()->find($price->product_id);

        return [
            'id' => $price->id,
            'product_id' => $price->product_id,
            'product_name' => $product?->name,
            'catalog_price' => $product ? (float) $product->price : null,
            'price' => (float) $price->price,
            'unit_type' => $product?->unit_type,
        ];
    }
}
