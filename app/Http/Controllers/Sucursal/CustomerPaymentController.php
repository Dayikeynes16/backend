<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\AuthorizesCustomerAccess;
use App\Http\Controllers\Concerns\HandlesCustomerGlobalPayments;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\CustomerGlobalPaymentService;
use App\Services\RecalculateClosedShifts;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cobros globales para admin-sucursal. El registro y la consulta viven en
 * `HandlesCustomerGlobalPayments`, compartido con `Caja\CustomerPaymentController`.
 * La **cancelación** es exclusiva de este rol y por eso vive aquí.
 */
class CustomerPaymentController extends Controller
{
    use AuthorizesCustomerAccess;
    use HandlesCustomerGlobalPayments;

    public function __construct(
        private SalePaymentService $salePaymentService,
        private CustomerGlobalPaymentService $globalPayments,
    ) {}

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

        $this->authorizeCustomerBranchAccess($customer);

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
        $this->globalPayments->broadcastPaymentChange($customerPayment, $affectedSaleIds, 'reverted');

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
}
