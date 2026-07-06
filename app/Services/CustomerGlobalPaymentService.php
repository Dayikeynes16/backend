<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cobro global de fiado: un abono del cliente distribuido FIFO (venta más
 * antigua primero) entre sus ventas con saldo. Única implementación compartida
 * por la web (Sucursal), el hub API y el asistente IA — antes vivía duplicada
 * inline en los dos controllers.
 *
 * Los montos y la distribución SIEMPRE se calculan aquí server-side. preview()
 * es la versión de solo lectura para mostrar el desglose antes de confirmar;
 * apply() es autoritativo: recalcula la distribución al momento de ejecutar,
 * dentro de una transacción con advisory lock por sucursal.
 *
 * La exigencia de turno abierto y su código de estado son responsabilidad de
 * cada caller (web 403, hub 409, confirmer del asistente 403).
 */
final class CustomerGlobalPaymentService
{
    public function __construct(private readonly SalePaymentService $salePayments) {}

    /**
     * Desglose FIFO sin persistir nada.
     *
     * @param  array<int, int>  $excludedSaleIds
     * @return array{sales: array<int, array<string, mixed>>, total_pending: float, amount_to_apply: float, change_given: float, remaining_debt: float}
     */
    public function preview(Customer $customer, float $amountReceived, string $method, array $excludedSaleIds = []): array
    {
        $amountReceived = round($amountReceived, 2);
        $sales = $this->pendingSales($customer, $excludedSaleIds)->get();

        $totalPending = round((float) $sales->sum('amount_pending'), 2);
        $amountToApply = round(min($amountReceived, $totalPending), 2);
        // Solo efectivo da cambio; con otros métodos apply() rechaza el excedente.
        $changeGiven = $method === 'cash' ? round(max(0, $amountReceived - $amountToApply), 2) : 0.0;

        $remaining = $amountToApply;
        $rows = [];
        foreach ($sales as $sale) {
            if ($remaining <= 0) {
                break;
            }
            $pending = round((float) $sale->amount_pending, 2);
            if ($pending <= 0) {
                continue;
            }
            $portion = round(min($remaining, $pending), 2);
            $rows[] = [
                'sale_id' => $sale->id,
                'folio' => $sale->folio,
                'date' => $sale->created_at?->toDateString(),
                'amount_pending' => $pending,
                'amount_to_apply' => $portion,
                'remaining_after' => round($pending - $portion, 2),
            ];
            $remaining = round($remaining - $portion, 2);
        }

        return [
            'sales' => $rows,
            'total_pending' => $totalPending,
            'amount_to_apply' => $amountToApply,
            'change_given' => $changeGiven,
            'remaining_debt' => round($totalPending - $amountToApply, 2),
        ];
    }

    /**
     * Ejecuta el cobro dentro de una transacción con advisory lock por sucursal.
     * Aborta 422 si no hay saldo o si un método distinto de efectivo excede la
     * deuda (no hay cambio). NO exige turno abierto — eso lo valida el caller.
     *
     * @param  array{amount_received: float|string, method: string, excluded_sale_ids?: array<int, int>, notes?: string|null}  $payload
     * @return array{customer_payment: CustomerPayment, applied: array<int, array<string, mixed>>, affected_sale_ids: array<int, int>}
     */
    public function apply(Customer $customer, User $user, array $payload): array
    {
        $amountReceived = round((float) $payload['amount_received'], 2);
        $method = (string) $payload['method'];
        $excluded = $payload['excluded_sale_ids'] ?? [];
        $notes = $payload['notes'] ?? null;

        return DB::transaction(function () use ($customer, $user, $amountReceived, $method, $excluded, $notes) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$customer->branch_id]);

            $sales = $this->pendingSales($customer, $excluded)->lockForUpdate()->get();

            $totalPending = round((float) $sales->sum('amount_pending'), 2);

            if ($totalPending <= 0) {
                abort(422, 'No hay ventas con saldo seleccionadas.');
            }

            if ($method !== 'cash' && $amountReceived > $totalPending) {
                abort(422, "Con {$method} no hay cambio — el monto debe ser menor o igual a \${$totalPending}.");
            }

            $amountToApply = min($amountReceived, $totalPending);
            $changeGiven = round($amountReceived - $amountToApply, 2);

            // Folio monotónico: withTrashed() garantiza que las cancelaciones
            // no reutilicen números.
            $count = CustomerPayment::withTrashed()
                ->withoutGlobalScopes()
                ->where('branch_id', $customer->branch_id)
                ->count();
            $folio = 'CG-'.str_pad($count + 1, 5, '0', STR_PAD_LEFT);

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

                $this->salePayments->recalculate($sale, $user);

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
    }

    /**
     * Broadcast post-commit de las ventas afectadas; nunca rompe el flujo.
     *
     * @param  array<int, int>  $saleIds
     */
    public function broadcastSaleUpdates(array $saleIds): void
    {
        foreach ($saleIds as $saleId) {
            $sale = Sale::withoutGlobalScopes()->find($saleId);
            if (! $sale) {
                continue;
            }
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

    /**
     * Ventas con saldo del cliente en orden FIFO. withoutGlobalScopes + filtros
     * explícitos para comportarse igual en web (tenant bound) y hub (Sanctum,
     * sin tenant resuelto).
     *
     * @param  array<int, int>  $excludedSaleIds
     */
    private function pendingSales(Customer $customer, array $excludedSaleIds): Builder
    {
        return Sale::withoutGlobalScopes()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->where('branch_id', $customer->branch_id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->where('amount_pending', '>', 0)
            ->when(! empty($excludedSaleIds), fn ($q) => $q->whereNotIn('id', $excludedSaleIds))
            ->orderBy('created_at', 'asc');
    }
}
