<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
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
            ->where('status', 'active')
            ->with(['items', 'payments'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->with(['category', 'presentations' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        $categories = Category::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $paymentMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        return Inertia::render('Sucursal/Workbench', [
            'sales' => $sales,
            'products' => $products,
            'categories' => $categories,
            'tenant' => app('tenant'),
            'paymentMethods' => $paymentMethods,
            'canCreate' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'canCancel' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
            'canEditPayments' => $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin'),
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
            'items.*.presentation_id' => 'nullable|integer',
        ]);

        $branchId = $user->branch_id;
        $tenantId = $user->tenant_id;

        $productIds = collect($request->items)->pluck('product_id')->unique();
        $products = Product::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('id', $productIds)
            ->with('presentations')
            ->get()
            ->keyBy('id');

        $missing = $productIds->diff($products->keys());
        if ($missing->isNotEmpty()) {
            return back()->with('error', 'Algunos productos no son validos.');
        }

        DB::transaction(function () use ($request, $branchId, $tenantId, $products, $user) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchId]);

            $count = Sale::withoutGlobalScopes()->where('branch_id', $branchId)->count();
            $folio = 'S-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

            $total = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = $products[$item['product_id']];
                $quantity = (float) $item['quantity'];

                // If presentation mode and presentation_id is provided
                if ($product->sale_mode === 'presentation' && ! empty($item['presentation_id'])) {
                    $presentation = $product->presentations->find($item['presentation_id']);
                    $unitPrice = $presentation ? (float) $presentation->price : (float) $product->price;
                    $productName = $product->name . ' - ' . ($presentation->name ?? '');
                } else {
                    $unitPrice = (float) $product->price;
                    $productName = $product->name;
                }

                $subtotal = round($quantity * $unitPrice, 2);
                $total += $subtotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $productName,
                    'unit_type' => $product->unit_type,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
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

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para cancelar ventas.');
        }

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === 'completed' || $sale->status === 'cancelled') {
            return back()->with('error', 'Esta venta no se puede cancelar.');
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $sale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancel_reason' => $validated['cancel_reason'],
        ]);

        return back()->with('success', "Venta {$sale->folio} cancelada.");
    }

    public function requestCancel(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === 'completed' || $sale->status === 'cancelled') {
            return back()->with('error', 'Esta venta no se puede cancelar.');
        }

        if ($sale->cancel_requested_at) {
            return back()->with('error', 'Ya existe una solicitud de cancelacion para esta venta.');
        }

        $validated = $request->validate([
            'cancel_request_reason' => 'required|string|max:500',
        ]);

        $sale->update([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $user->id,
            'cancel_request_reason' => $validated['cancel_request_reason'],
        ]);

        return back()->with('success', "Solicitud de cancelacion enviada para {$sale->folio}.");
    }
}
