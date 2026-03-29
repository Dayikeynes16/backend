<?php

namespace App\Http\Controllers\Sucursal;

use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
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

        $isAdmin = $user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin');

        $sales = Sale::where('branch_id', $branchId)
            ->where(function ($q) use ($isAdmin) {
                $q->where('status', 'active');
                if ($isAdmin) {
                    $q->orWhere(function ($q2) {
                        $q2->where('status', 'completed')
                            ->whereDate('completed_at', now());
                    });
                }
            })
            ->with(['items', 'payments', 'lockedByUser:id,name'])
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
            'branchId' => $branchId,
            'branchInfo' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'ticket_config' => $branch->ticket_config,
            ],
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

        $tenant = app('tenant');
        $monthlySales = Sale::where('branch_id', $branchId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        if ($monthlySales >= $tenant->max_sales_per_branch_month) {
            return back()->with('error', 'Se alcanzo el limite de ventas mensuales para esta sucursal.');
        }

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
                if (in_array($product->sale_mode, ['presentation', 'both']) && ! empty($item['presentation_id'])) {
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

    public function reopen(Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para reabrir ventas.');
        }

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status !== 'completed') {
            return back()->with('error', 'Solo se pueden enviar a pendiente ventas completadas.');
        }

        $totalPaid = $sale->payments()->sum('amount');
        $pending = round((float) $sale->total - $totalPaid, 2);

        $sale->update([
            'status' => 'active',
            'amount_pending' => max($pending, 0),
            'completed_at' => null,
        ]);

        return back()->with('success', "Venta {$sale->folio} enviada a pendiente.");
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

        if ($sale->status === 'cancelled') {
            return back()->with('error', 'Esta venta ya esta cancelada.');
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $wasCompleted = $sale->status === 'completed';

        DB::transaction(function () use ($sale, $user, $validated, $wasCompleted) {
            // Soft-delete associated payments and reset amounts
            $sale->payments()->delete();

            $sale->update([
                'status' => 'cancelled',
                'amount_paid' => 0,
                'amount_pending' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $validated['cancel_reason'],
            ]);

            // Auto-recalculate affected closed shifts
            if ($wasCompleted) {
                $this->recalculateAffectedShifts($sale);
            }
        });

        SaleUpdated::dispatch($sale->fresh());

        $msg = "Venta {$sale->folio} cancelada.";
        if ($wasCompleted) {
            $msg .= ' Los cortes de caja afectados fueron recalculados.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Recalculate any closed shifts that included payments from a cancelled sale.
     */
    private function recalculateAffectedShifts(Sale $sale): void
    {
        // Get the original payments (with trashed since we just soft-deleted them)
        $payments = Payment::withTrashed()
            ->where('sale_id', $sale->id)
            ->get();

        // Find affected shifts by user_id + time range
        $affectedUserIds = $payments->pluck('user_id')->unique();

        foreach ($affectedUserIds as $userId) {
            $userPayments = $payments->where('user_id', $userId);
            $earliest = $userPayments->min('created_at');

            // Find closed shifts that overlap with these payments
            $shifts = CashRegisterShift::where('user_id', $userId)
                ->whereNotNull('closed_at')
                ->where('opened_at', '<=', $earliest)
                ->get();

            foreach ($shifts as $shift) {
                // Recalculate: query active (non-deleted) payments for this shift
                $shiftPayments = Payment::where('user_id', $shift->user_id)
                    ->where('created_at', '>=', $shift->opened_at)
                    ->where('created_at', '<=', $shift->closed_at)
                    ->get();

                $totalCash = (float) $shiftPayments->where('method', 'cash')->sum('amount');
                $totalCard = (float) $shiftPayments->where('method', 'card')->sum('amount');
                $totalTransfer = (float) $shiftPayments->where('method', 'transfer')->sum('amount');
                $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

                $expected = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
                $declared = (float) $shift->declared_amount;

                $shift->update([
                    'total_cash' => $totalCash,
                    'total_card' => $totalCard,
                    'total_transfer' => $totalTransfer,
                    'total_sales' => $totalCash + $totalCard + $totalTransfer,
                    'sale_count' => $shiftPayments->pluck('sale_id')->unique()->count(),
                    'expected_amount' => $expected,
                    'difference' => round($declared - $expected, 2),
                ]);
            }
        }
    }

    public function requestCancel(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === 'cancelled') {
            return back()->with('error', 'Esta venta ya esta cancelada.');
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
