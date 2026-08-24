<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\RegisterCustomerPaymentRequest;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Services\CustomerGlobalPaymentService;
use App\Services\PaymentReceiptService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Registro y consulta de cobros globales (pago del cliente distribuido FIFO
 * entre sus ventas con saldo). Idéntico para admin-sucursal y cajero: ambos
 * necesitan turno abierto y solo operan sobre clientes de su sucursal.
 *
 * La distribución vive en `CustomerGlobalPaymentService`; aquí solo hay
 * autorización, turno, comprobantes y formato de respuesta.
 *
 * La **cancelación** de un cobro NO está aquí a propósito: es exclusiva de
 * admin-sucursal y vive en `Sucursal\CustomerPaymentController@destroy`.
 *
 * Requiere `AuthorizesCustomerAccess` en el controller que lo usa.
 */
trait HandlesCustomerGlobalPayments
{
    /**
     * Register a global customer payment distributed FIFO across pending sales.
     */
    public function store(
        RegisterCustomerPaymentRequest $request,
        Customer $customer,
        CustomerGlobalPaymentService $globalPayments,
    ): JsonResponse {
        $user = Auth::user();

        $this->authorizeCustomerBranchAccess($customer);

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
            $result = $globalPayments->apply($customer, $user, [
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

        $cp = $result['customer_payment'];

        // Post-commit: un solo aviso para las N ventas que tocó el cobro.
        $globalPayments->broadcastPaymentChange($cp, $result['affected_sale_ids']);

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
     * Show detail of a global customer payment for the detail modal.
     */
    public function show(Customer $customer, CustomerPayment $customerPayment): JsonResponse
    {
        $this->authorizeCustomerBranchAccess($customer);

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
