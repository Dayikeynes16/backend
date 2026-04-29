<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $customers = Customer::where('branch_id', $branchId)
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q2) => $q2->where('name', 'ilike', "%{$s}%")
                ->orWhere('phone', 'ilike', "%{$s}%")
            ))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(! $request->status, fn ($q) => $q->where('status', 'active'))
            ->with(['prices.product:id,name,price'])
            // Subquery por cliente: deuda actual = SUM(amount_pending) en ventas
            // no canceladas. Si no tiene ventas, retorna null → UI lo trata como 0.
            ->withSum([
                'sales as total_owed' => fn ($q) => $q->where('status', '!=', SaleStatus::Cancelled->value),
            ], 'amount_pending')
            ->orderBy('name')
            ->get();

        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'unit_type', 'sale_mode']);

        $branch = Branch::withoutGlobalScopes()->find($branchId);
        $allowedMethods = $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        return Inertia::render('Sucursal/Clientes/Index', [
            'customers' => $customers,
            'products' => $products,
            'filters' => $request->only('search', 'status'),
            'tenant' => app('tenant'),
            'allowedPaymentMethods' => $allowedMethods,
            'customersSummary' => $this->buildCustomersSummary($branchId),
        ]);
    }

    /**
     * Resumen agregado de la cartera de clientes de la sucursal.
     * Es panorama de la cartera completa — no se filtra por search/status del listado.
     */
    private function buildCustomersSummary(int $branchId): array
    {
        $statusCounts = Customer::where('branch_id', $branchId)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $active = (int) ($statusCounts['active'] ?? 0);
        $inactive = (int) ($statusCounts['inactive'] ?? 0);
        $total = $active + $inactive;

        // Deuda total en la sucursal — sumar amount_pending de ventas no canceladas
        // de clientes de esta sucursal.
        $debtRow = DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('customers.branch_id', $branchId)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->where('sales.amount_pending', '>', 0)
            ->whereNull('sales.deleted_at')
            ->selectRaw('
                COALESCE(SUM(sales.amount_pending), 0) as total_debt,
                COUNT(DISTINCT sales.customer_id) as customers_with_debt
            ')
            ->first();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'total_debt' => (float) ($debtRow->total_debt ?? 0),
            'customers_with_debt' => (int) ($debtRow->customers_with_debt ?? 0),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

        $exists = Customer::where('branch_id', $user->branch_id)
            ->where('phone', $validated['phone'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['phone' => 'Ya existe un cliente con este telefono en esta sucursal.']);
        }

        Customer::create([
            ...$validated,
            'branch_id' => $user->branch_id,
        ]);

        return back()->with('success', 'Cliente registrado.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $user = Auth::user();

        if ($customer->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $duplicate = Customer::where('branch_id', $user->branch_id)
            ->where('phone', $validated['phone'])
            ->where('id', '!=', $customer->id)
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['phone' => 'Ya existe otro cliente con este telefono.']);
        }

        $customer->update($validated);

        return back()->with('success', 'Cliente actualizado.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $user = Auth::user();

        if ($customer->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($customer->sales()->exists()) {
            $customer->update(['status' => 'inactive']);

            return back()->with('success', 'Cliente desactivado (tiene ventas asociadas).');
        }

        $customer->delete();

        return back()->with('success', 'Cliente eliminado.');
    }
}
