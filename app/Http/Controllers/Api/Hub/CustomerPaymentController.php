<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\RecalculateClosedShifts;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Cobro global de fiado (abono distribuido FIFO sobre las ventas pendientes del
 * cliente) desde el hub. Replica la lógica de Sucursal\CustomerPaymentController
 * reusando SalePaymentService::recalculate y RecalculateClosedShifts; devuelve
 * JSON en lugar de respuestas Inertia.
 */
class CustomerPaymentController extends Controller
{
    public function __construct(private SalePaymentService $salePaymentService) {}

    /** Ledger del cliente: ventas pendientes + cobros globales recientes + deuda. */
    public function index(Request $request, int $customer): JsonResponse
    {
        $found = $this->findCustomer($request, $customer);
        $branch = Branch::withoutGlobalScopes()->find($request->user()->branch_id);

        $pending = Sale::withoutGlobalScopes()
            ->where('customer_id', $found->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->where('amount_pending', '>', 0)
            ->orderBy('created_at')
            ->get(['id', 'folio', 'total', 'amount_pending', 'created_at']);

        $movements = CustomerPayment::withoutGlobalScopes()
            ->where('customer_id', $found->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'pending_sales' => $pending->map(fn ($s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'total' => (float) $s->total,
                'amount_pending' => (float) $s->amount_pending,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
            'recent_movements' => $movements->map(fn ($m) => [
                'id' => $m->id,
                'folio' => $m->folio,
                'method' => $m->method,
                'amount_received' => (float) $m->amount_received,
                'amount_applied' => (float) $m->amount_applied,
                'change_given' => (float) $m->change_given,
                'sales_affected_count' => $m->sales_affected_count,
                'cancelled_at' => $m->cancelled_at?->toIso8601String(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'total_owed' => round((float) $pending->sum('amount_pending'), 2),
            'payment_methods' => $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'],
        ]);
    }

    /** Cobro global FIFO. Requiere turno abierto. */
    public function store(Request $request, int $customer): JsonResponse
    {
        $user = $request->user();
        $found = $this->findCustomer($request, $customer);

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->exists();
        if (! $hasOpenShift) {
            return response()->json(['message' => 'Debes tener un turno abierto para registrar pagos.'], 409);
        }

        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);
        $enabled = $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'];

        $validated = $request->validate([
            'amount_received' => 'required|numeric|gt:0',
            'method' => ['required', 'string', Rule::in($enabled)],
            'excluded_sale_ids' => 'nullable|array',
            'excluded_sale_ids.*' => 'integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        $amountReceived = round((float) $validated['amount_received'], 2);
        $method = $validated['method'];
        $excluded = $validated['excluded_sale_ids'] ?? [];
        $notes = $validated['notes'] ?? null;

        $result = DB::transaction(function () use ($found, $user, $amountReceived, $method, $excluded, $notes) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$found->branch_id]);

            $sales = Sale::withoutGlobalScopes()
                ->where('customer_id', $found->id)
                ->where('branch_id', $found->branch_id)
                ->where('status', '!=', SaleStatus::Cancelled->value)
                ->accountable()
                ->where('amount_pending', '>', 0)
                ->when(! empty($excluded), fn ($q) => $q->whereNotIn('id', $excluded))
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $totalPending = round((float) $sales->sum('amount_pending'), 2);
            if ($totalPending <= 0) {
                abort(422, 'No hay ventas con saldo seleccionadas.');
            }
            if ($method !== 'cash' && $amountReceived > $totalPending) {
                abort(422, "Con {$method} no hay cambio — el monto debe ser menor o igual a \${$totalPending}.");
            }

            $amountToApply = min($amountReceived, $totalPending);
            $changeGiven = round($amountReceived - $amountToApply, 2);

            $count = CustomerPayment::withTrashed()->withoutGlobalScopes()
                ->where('branch_id', $found->branch_id)->count();
            $folio = 'CG-'.str_pad($count + 1, 5, '0', STR_PAD_LEFT);

            $customerPayment = CustomerPayment::create([
                'tenant_id' => $found->tenant_id,
                'branch_id' => $found->branch_id,
                'customer_id' => $found->id,
                'user_id' => $user->id,
                'folio' => $folio,
                'method' => $method,
                'amount_received' => $amountReceived,
                'amount_applied' => $amountToApply,
                'change_given' => $changeGiven,
                'sales_affected_count' => 0,
                'notes' => $notes,
            ]);

            $remaining = $amountToApply;
            $applied = [];
            foreach ($sales as $sale) {
                if ($remaining <= 0) {
                    break;
                }
                $currentPending = (float) $sale->fresh()->amount_pending;
                if ($currentPending <= 0) {
                    continue;
                }
                $portion = round(min($remaining, $currentPending), 2);
                if ($portion <= 0) {
                    continue;
                }

                Payment::create([
                    'sale_id' => $sale->id,
                    'customer_payment_id' => $customerPayment->id,
                    'user_id' => $user->id,
                    'method' => $method,
                    'amount' => $portion,
                ]);

                $this->salePaymentService->recalculate($sale, $user);

                $fresh = $sale->fresh();
                $applied[] = [
                    'sale_id' => $sale->id,
                    'folio' => $sale->folio,
                    'amount' => $portion,
                    'completed' => $fresh->status === SaleStatus::Completed,
                    'new_pending' => (float) $fresh->amount_pending,
                ];
                $remaining = round($remaining - $portion, 2);
            }

            $customerPayment->update(['sales_affected_count' => count($applied)]);

            return [
                'payment' => $customerPayment->fresh(),
                'applied' => $applied,
                'affected_sale_ids' => collect($applied)->pluck('sale_id')->all(),
            ];
        });

        $this->broadcast($result['affected_sale_ids']);

        $cp = $result['payment'];

        return response()->json([
            'customer_payment' => [
                'id' => $cp->id,
                'folio' => $cp->folio,
                'method' => $cp->method,
                'amount_received' => (float) $cp->amount_received,
                'amount_applied' => (float) $cp->amount_applied,
                'change_given' => (float) $cp->change_given,
                'sales_affected_count' => $cp->sales_affected_count,
            ],
            'applied' => $result['applied'],
        ], 201);
    }

    /** Cancela un cobro global (solo admin-sucursal). */
    public function destroy(Request $request, int $customer, int $payment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('admin-sucursal') || $user->hasRole('superadmin'), 403, 'No tienes permiso para cancelar cobros globales.');

        $found = $this->findCustomer($request, $customer);
        $cp = CustomerPayment::withoutGlobalScopes()
            ->where('customer_id', $found->id)
            ->findOrFail($payment);

        if ($cp->cancelled_at !== null) {
            return response()->json(['message' => 'Este cobro ya fue cancelado.'], 422);
        }

        $validated = $request->validate(['cancel_reason' => 'required|string|max:500']);

        $affected = [];
        DB::transaction(function () use ($cp, $user, $validated, &$affected) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$cp->branch_id]);

            $children = Payment::where('customer_payment_id', $cp->id)->get();
            $saleIds = $children->pluck('sale_id')->unique()->values();
            foreach ($children as $child) {
                $child->delete();
            }
            $sales = Sale::withoutGlobalScopes()->whereIn('id', $saleIds)->lockForUpdate()->get();
            foreach ($sales as $sale) {
                $this->salePaymentService->recalculate($sale, $user);
            }
            $affected = $saleIds->all();

            $cp->update(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $validated['cancel_reason']]);
            $cp->delete();
        });

        foreach ($affected as $saleId) {
            $sale = Sale::withoutGlobalScopes()->find($saleId);
            if ($sale) {
                app(RecalculateClosedShifts::class)->forSale($sale);
            }
        }
        $this->broadcast($affected);

        return response()->json(['message' => "Cobro {$cp->folio} cancelado.", 'affected_sale_ids' => $affected]);
    }

    private function findCustomer(Request $request, int $customer): Customer
    {
        return Customer::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->findOrFail($customer);
    }

    /** @param  array<int, int>  $saleIds */
    private function broadcast(array $saleIds): void
    {
        foreach ($saleIds as $saleId) {
            $sale = Sale::withoutGlobalScopes()->find($saleId);
            if (! $sale) {
                continue;
            }
            try {
                SaleUpdated::dispatch($sale);
            } catch (\Throwable $e) {
                Log::warning('SaleUpdated broadcast failed', ['sale_id' => $saleId, 'error' => $e->getMessage()]);
            }
        }
    }
}
