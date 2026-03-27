<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WorkbenchController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $sales = Sale::where('branch_id', $branchId)
            ->with(['items', 'payments'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when(!$request->status, fn ($q) => $q->whereIn('status', ['active', 'pending']))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->with('category')
            ->orderBy('name')
            ->get();

        $categories = Category::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return Inertia::render('Sucursal/Workbench', [
            'sales' => $sales,
            'products' => $products,
            'categories' => $categories,
            'filters' => $request->only('status'),
            'tenant' => app('tenant'),
            'canCreate' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'canCancel' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin-sucursal') && !$user->hasRole('admin-empresa') && !$user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para crear ventas.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        $branchId = $user->branch_id;
        $tenantId = $user->tenant_id;

        $productIds = collect($request->items)->pluck('product_id')->unique();
        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $missing = $productIds->diff($products->keys());
        if ($missing->isNotEmpty()) {
            return back()->with('error', 'Algunos productos no son validos.');
        }

        DB::transaction(function () use ($request, $branchId, $tenantId, $products, $user) {
            $count = Sale::withoutGlobalScopes()->where('branch_id', $branchId)->count();
            $folio = 'S-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

            $total = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = $products[$item['product_id']];
                $quantity = (float) $item['quantity'];
                $subtotal = round($quantity * (float) $product->price, 2);
                $total += $subtotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_type' => $product->unit_type,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'subtotal' => $subtotal,
                ];
            }

            $sale = Sale::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'folio' => $folio,
                'total' => round($total, 2),
                'amount_paid' => 0,
                'amount_pending' => round($total, 2),
                'origin' => 'admin',
                'origin_name' => 'Administrador',
                'status' => 'active',
            ]);

            foreach ($itemsData as $data) {
                $sale->items()->create($data);
            }
        });

        return back()->with('success', 'Venta creada.');
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin-sucursal') && !$user->hasRole('admin-empresa') && !$user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para cancelar ventas.');
        }

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === 'completed' || $sale->status === 'cancelled') {
            return back()->with('error', 'Esta venta no se puede cancelar.');
        }

        $sale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
        ]);

        return back()->with('success', "Venta {$sale->folio} cancelada.");
    }
}
