<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCustomerPaymentRequest;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\CustomerGlobalPaymentService;
use App\Services\PaymentReceiptService;
use App\Services\RecalculateClosedShifts;
use App\Services\SalePaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerPaymentController extends Controller
{
    public function __construct(
        private SalePaymentService $salePaymentService,
        private CustomerGlobalPaymentService $globalPayments,
    ) {}

    /**
     * Register a global customer payment distributed FIFO across pending sales.
     * La distribución vive en CustomerGlobalPaymentService (compartida con el
     * hub y el asistente IA); aquí solo autorización, turno y formato Inertia.
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

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $canAttach = (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required);
        $receiptFiles = $canAttach && ($validated['method'] ?? null) === 'transfer'
            ? ($request->file('receipts') ?? [])
            : [];
        if ($branch->payment_receipts_required && ($validated['method'] ?? null) === 'transfer' && $receiptFiles === []) {
            return response()->json([
                'message' => 'Adjunta el comprobante de la transferencia.',
                'errors' => ['receipts' => ['Adjunta el comprobante de la transferencia.']],
            ], 422);
        }

        try {
            $result = $this->globalPayments->apply($customer, $user, [
                'amount_received' => (float) $validated['amount_received'],
                'method' => $validated['method'],
                'excluded_sale_ids' => $validated['excluded_sale_ids'] ?? [],
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        // Post-commit: broadcast sale updates
        $this->globalPayments->broadcastSaleUpdates($result['affected_sale_ids']);

        $cp = $result['customer_payment'];

        // El comprobante va en el CustomerPayment padre; los Payment hijos que
        // crea el servicio (uno por venta afectada) no llevan comprobante propio.
        // No es necesario envolver el servicio en la misma transacción: si el
        // attach falla, el cobro queda válido sin comprobante (preferible).
        if ($receiptFiles !== []) {
            app(PaymentReceiptService::class)->attach($cp, $receiptFiles, $user->id);
        }

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
     * Cancel a global customer payment: soft-delete children, recalculate
     * affected sales, recalculate affected closed shifts, soft-delete parent.
     */
    public function destroy(Request $request, Customer $customer, CustomerPayment $customerPayment): JsonResponse
    {
        $user = Auth::user();

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('admin-empresa') && ! $user->hasRole('superadmin')) {
            abort(403, 'No tienes permiso para cancelar cobros globales.');
        }

        if ($customer->branch_id !== $user->branch_id) {
            abort(403, 'Cliente fuera de tu sucursal.');
        }

        if ($customerPayment->customer_id !== $customer->id) {
            abort(404);
        }

        if ($customerPayment->cancelled_at !== null) {
            return response()->json(['message' => 'Este cobro ya fue cancelado.'], 422);
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $affectedSaleIds = [];

        DB::transaction(function () use ($customerPayment, $user, $validated, &$affectedSaleIds) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$customerPayment->branch_id]);

            $children = Payment::where('customer_payment_id', $customerPayment->id)->get();
            $saleIds = $children->pluck('sale_id')->unique()->values();

            foreach ($children as $child) {
                $child->delete(); // soft-delete
            }

            // Recalcula cada venta afectada
            $sales = Sale::whereIn('id', $saleIds)->lockForUpdate()->get();
            foreach ($sales as $sale) {
                $this->salePaymentService->recalculate($sale, $user);
            }

            $affectedSaleIds = $saleIds->all();

            $customerPayment->update([
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $validated['cancel_reason'],
            ]);
            $customerPayment->delete(); // soft-delete parent
        });

        // Post-commit: recalc de shifts cerrados afectados + broadcast
        foreach ($affectedSaleIds as $saleId) {
            $sale = Sale::find($saleId);
            if (! $sale) {
                continue;
            }

            $this->recalculateAffectedShifts($sale);
        }
        $this->globalPayments->broadcastSaleUpdates($affectedSaleIds);

        return response()->json([
            'message' => "Cobro {$customerPayment->folio} cancelado.",
            'affected_sale_ids' => $affectedSaleIds,
        ]);
    }

    /**
     * Recalcula turnos cerrados que incluyeron pagos de esta venta.
     */
    private function recalculateAffectedShifts(Sale $sale): void
    {
        app(RecalculateClosedShifts::class)->forSale($sale);
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
