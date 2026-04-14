<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCustomerPaymentRequest;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerPaymentController extends Controller
{
    public function __construct(private SalePaymentService $salePaymentService) {}

    /**
     * Register a global customer payment distributed FIFO across pending sales.
     */
    public function store(RegisterCustomerPaymentRequest $request, Customer $customer): JsonResponse
    {
        $user = Auth::user();

        if ($customer->branch_id !== $user->branch_id) {
            abort(403, 'Cliente fuera de tu sucursal.');
        }

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if (! $hasOpenShift) {
            return response()->json([
                'message' => 'Debes tener un turno abierto para registrar pagos.',
            ], 403);
        }

        $validated = $request->validated();
        $amountReceived = round((float) $validated['amount_received'], 2);
        $method = $validated['method'];
        $excludedSaleIds = $validated['excluded_sale_ids'] ?? [];
        $notes = $validated['notes'] ?? null;

        $result = null;

        try {
            $result = DB::transaction(function () use (
                $customer, $user, $amountReceived, $method, $excludedSaleIds, $notes
            ) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$customer->branch_id]);

                $sales = Sale::where('customer_id', $customer->id)
                    ->where('branch_id', $customer->branch_id)
                    ->where('status', '!=', SaleStatus::Cancelled->value)
                    ->where('amount_pending', '>', 0)
                    ->when(! empty($excludedSaleIds), fn ($q) => $q->whereNotIn('id', $excludedSaleIds))
                    ->orderBy('created_at', 'asc')
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

                // Folio monotónico: withTrashed() garantiza que cancelaciones
                // futuras (fase 2) no reutilicen números.
                $count = CustomerPayment::withTrashed()
                    ->withoutGlobalScopes()
                    ->where('branch_id', $customer->branch_id)
                    ->count();
                $folio = 'CG-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

                $customerPayment = CustomerPayment::create([
                    'tenant_id' => $customer->tenant_id,
                    'branch_id' => $customer->branch_id,
                    'customer_id' => $customer->id,
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
                    if ($remaining <= 0) break;

                    $currentPending = (float) $sale->fresh()->amount_pending;
                    if ($currentPending <= 0) continue;

                    $portion = round(min($remaining, $currentPending), 2);
                    if ($portion <= 0) continue;

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
                    'customer_payment' => $customerPayment->fresh(),
                    'applied' => $applied,
                    'affected_sale_ids' => collect($applied)->pluck('sale_id')->all(),
                ];
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        // Post-commit: broadcast sale updates
        foreach ($result['affected_sale_ids'] as $saleId) {
            $sale = Sale::find($saleId);
            if ($sale) {
                try {
                    SaleUpdated::dispatch($sale);
                } catch (\Throwable $e) {
                    Log::warning('SaleUpdated broadcast failed', [
                        'sale_id' => $saleId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $cp = $result['customer_payment'];

        return response()->json([
            'customer_payment' => [
                'id' => $cp->id,
                'folio' => $cp->folio,
                'method' => $cp->method,
                'amount_received' => (float) $cp->amount_received,
                'amount_applied' => (float) $cp->amount_applied,
                'change_given' => (float) $cp->change_given,
                'sales_affected_count' => $cp->sales_affected_count,
                'created_at' => $cp->created_at,
            ],
            'applied' => $result['applied'],
        ], 201);
    }

    /**
     * Show detail of a global customer payment for the detail modal.
     */
    public function show(Customer $customer, CustomerPayment $customerPayment): JsonResponse
    {
        $user = Auth::user();

        if ($customer->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($customerPayment->customer_id !== $customer->id) {
            abort(404);
        }

        $customerPayment->load([
            'user:id,name',
            'payments' => fn ($q) => $q->with('sale:id,folio,total,amount_pending,status,created_at'),
        ]);

        return response()->json([
            'id' => $customerPayment->id,
            'folio' => $customerPayment->folio,
            'method' => $customerPayment->method,
            'amount_received' => (float) $customerPayment->amount_received,
            'amount_applied' => (float) $customerPayment->amount_applied,
            'change_given' => (float) $customerPayment->change_given,
            'sales_affected_count' => $customerPayment->sales_affected_count,
            'notes' => $customerPayment->notes,
            'created_at' => $customerPayment->created_at,
            'cashier' => $customerPayment->user ? [
                'id' => $customerPayment->user->id,
                'name' => $customerPayment->user->name,
            ] : null,
            'applications' => $customerPayment->payments->map(fn ($p) => [
                'payment_id' => $p->id,
                'sale_id' => $p->sale_id,
                'sale_folio' => $p->sale?->folio,
                'sale_date' => $p->sale?->created_at,
                'amount' => (float) $p->amount,
                'sale_status_after' => $p->sale?->status?->value,
                'sale_total' => (float) ($p->sale?->total ?? 0),
                'sale_amount_pending_after' => (float) ($p->sale?->amount_pending ?? 0),
            ]),
        ]);
    }
}
