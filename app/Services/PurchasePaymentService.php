<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mantiene amount_paid / amount_pending de una Purchase sincronizados con
 * la suma de sus provider_payments vivos (no cancelados, no soft-deleted).
 *
 * Análogo a SalePaymentService. Único punto que muta esos campos.
 */
final class PurchasePaymentService
{
    /**
     * Recalcula amount_paid y amount_pending para una compra. Lo llaman:
     *  - HandlesPurchases tras crear/editar una compra (puede cambiar total)
     *  - applyPayment / cancelPayment tras tocar el pago
     */
    public function recalculate(Purchase $purchase): Purchase
    {
        $paid = (float) DB::table('provider_payments')
            ->where('purchase_id', $purchase->id)
            ->whereNull('deleted_at')
            ->whereNull('cancelled_at')
            ->sum('amount');

        $total = (float) $purchase->total;
        $pending = max(0, round($total - $paid, 2));

        $purchase->forceFill([
            'amount_paid' => round($paid, 2),
            'amount_pending' => $pending,
        ])->save();

        return $purchase->fresh();
    }

    /**
     * Registra un pago a una compra específica. Valida que no exceda el saldo
     * pendiente. Devuelve el ProviderPayment creado.
     *
     * @param  array{
     *     amount: numeric,
     *     payment_method: string,
     *     paid_at?: string|Carbon|null,
     *     reference?: string|null,
     *     notes?: string|null,
     *     user_id?: int|null,
     *     cash_register_shift_id?: int|null,
     * }  $payload
     *
     * @throws ValidationException si sobre-pago
     */
    public function applyPayment(Purchase $purchase, array $payload): ProviderPayment
    {
        if ($purchase->status === PurchaseStatus::Cancelled) {
            throw ValidationException::withMessages([
                'purchase' => 'No se puede registrar un pago en una compra cancelada.',
            ]);
        }

        $amount = round((float) $payload['amount'], 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El monto del pago debe ser mayor a cero.',
            ]);
        }

        return DB::transaction(function () use ($purchase, $payload, $amount) {
            // Lock para evitar pagos simultáneos que sumen pasando el total.
            $locked = Purchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->first();

            $remaining = round((float) $locked->total - (float) $locked->amount_paid, 2);
            if ($amount > $remaining + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto excede el saldo pendiente ($'.number_format($remaining, 2).').',
                ]);
            }

            $payment = ProviderPayment::create([
                'tenant_id' => $locked->tenant_id,
                'branch_id' => $locked->branch_id,
                'cash_register_shift_id' => $payload['cash_register_shift_id'] ?? null,
                'provider_id' => $locked->provider_id,
                'purchase_id' => $locked->id,
                'paid_at' => $this->parseDate($payload['paid_at'] ?? null),
                'amount' => $amount,
                'payment_method' => $this->normalizeMethod($payload['payment_method']),
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'user_id' => $payload['user_id'] ?? null,
            ]);

            $this->recalculate($locked);

            if ($locked->purchase_id ?? $locked->id) {
                app(AuditLogger::class)->logPaymentAdded(
                    $locked,
                    $amount,
                    $payment->payment_method->value,
                    $payload['user_id'] ?? null,
                );
            }

            return $payment;
        });
    }

    /**
     * Cancela un pago: lo marca como `cancelled_at` y recalcula la compra (si
     * tenía una asociada) o todas las afectadas por un pago "a cuenta".
     */
    public function cancelPayment(ProviderPayment $payment, ?int $cancelledBy, string $reason): void
    {
        if ($payment->cancelled_at !== null) {
            return; // Idempotente.
        }

        DB::transaction(function () use ($payment, $cancelledBy, $reason) {
            $payment->update([
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
                'cancel_reason' => $reason,
            ]);

            if ($payment->purchase_id) {
                $purchase = Purchase::find($payment->purchase_id);
                if ($purchase) {
                    $this->recalculate($purchase);

                    app(AuditLogger::class)->logPaymentCancelled(
                        $purchase,
                        (float) $payment->amount,
                        $payment->payment_method->value,
                        $reason,
                        $cancelledBy,
                    );
                }
            }
        });
    }

    /**
     * Desglose FIFO de un pago "a cuenta" SIN persistir nada: misma query que
     * applyAccountPayment (sin lock) para mostrar al usuario cómo se repartirá
     * el monto antes de confirmar (asistente IA). El excedente que quedaría a
     * favor del proveedor se reporta como `surplus`.
     *
     * @return array{purchases: array<int, array<string, mixed>>, total_pending: float, amount_to_apply: float, surplus: float}
     */
    public function previewAccountPayment(Provider $provider, float $amount, ?int $branchId = null): array
    {
        $amount = round($amount, 2);

        $pending = Purchase::query()
            ->where('provider_id', $provider->id)
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->where('amount_pending', '>', 0)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->get();

        $totalPending = round((float) $pending->sum('amount_pending'), 2);

        $remaining = $amount;
        $rows = [];
        foreach ($pending as $purchase) {
            if ($remaining <= 0) {
                break;
            }
            $pendingAmount = round((float) $purchase->amount_pending, 2);
            $toApply = round(min($remaining, $pendingAmount), 2);
            $rows[] = [
                'purchase_id' => $purchase->id,
                'folio' => $purchase->folio,
                'date' => $purchase->purchased_at?->toDateString(),
                'amount_pending' => $pendingAmount,
                'amount_to_apply' => $toApply,
                'remaining_after' => round($pendingAmount - $toApply, 2),
            ];
            $remaining = round($remaining - $toApply, 2);
        }

        return [
            'purchases' => $rows,
            'total_pending' => $totalPending,
            'amount_to_apply' => round(min($amount, $totalPending), 2),
            'surplus' => round(max(0, $amount - $totalPending), 2),
        ];
    }

    /**
     * Pago "a cuenta" del proveedor: distribuye el monto en FIFO sobre las
     * compras con saldo pendiente (más antigua primero, por purchased_at).
     * Devuelve la lista de ProviderPayments creados (uno por compra cubierta).
     * Si sobra dinero después de saldar todas las compras pendientes, crea un
     * pago "huérfano" con `purchase_id = null` para registrar el sobrante.
     *
     * @param  array{
     *     amount: numeric,
     *     payment_method: string,
     *     paid_at?: string|Carbon|null,
     *     reference?: string|null,
     *     notes?: string|null,
     *     user_id?: int|null,
     *     branch_id?: int|null,
     * }  $payload
     * @return array<int, ProviderPayment>
     */
    public function applyAccountPayment(Provider $provider, array $payload): array
    {
        $amount = round((float) $payload['amount'], 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El monto debe ser mayor a cero.',
            ]);
        }

        return DB::transaction(function () use ($provider, $payload, $amount) {
            $remaining = $amount;
            $created = [];
            $paidAt = $this->parseDate($payload['paid_at'] ?? null);
            $method = $this->normalizeMethod($payload['payment_method']);

            $pending = Purchase::query()
                ->where('provider_id', $provider->id)
                ->where('status', '!=', PurchaseStatus::Cancelled)
                ->where('amount_pending', '>', 0)
                ->when(isset($payload['branch_id']), fn ($q) => $q->where('branch_id', $payload['branch_id']))
                ->orderBy('purchased_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $purchase) {
                if ($remaining <= 0) {
                    break;
                }
                $pendingAmount = round((float) $purchase->amount_pending, 2);
                $toApply = min($remaining, $pendingAmount);

                $created[] = ProviderPayment::create([
                    'tenant_id' => $provider->tenant_id,
                    'branch_id' => $purchase->branch_id,
                    'provider_id' => $provider->id,
                    'purchase_id' => $purchase->id,
                    'paid_at' => $paidAt,
                    'amount' => $toApply,
                    'payment_method' => $method,
                    'reference' => $payload['reference'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'user_id' => $payload['user_id'] ?? null,
                ]);

                $this->recalculate($purchase);
                $remaining = round($remaining - $toApply, 2);
            }

            // Sobrante: deja un registro a-cuenta sin purchase_id para no perder dinero.
            if ($remaining > 0) {
                $created[] = ProviderPayment::create([
                    'tenant_id' => $provider->tenant_id,
                    'branch_id' => $payload['branch_id'] ?? $provider->branch_id ?? null,
                    'provider_id' => $provider->id,
                    'purchase_id' => null,
                    'paid_at' => $paidAt,
                    'amount' => $remaining,
                    'payment_method' => $method,
                    'reference' => $payload['reference'] ?? null,
                    'notes' => trim(($payload['notes'] ?? '').' [excedente a favor del proveedor]'),
                    'user_id' => $payload['user_id'] ?? null,
                ]);
            }

            return $created;
        });
    }

    private function parseDate(string|Carbon|null $date): CarbonImmutable
    {
        if ($date instanceof Carbon) {
            return CarbonImmutable::instance($date);
        }
        if (is_string($date) && trim($date) !== '') {
            return CarbonImmutable::parse($date);
        }

        return CarbonImmutable::now();
    }

    private function normalizeMethod(string $method): string
    {
        $enum = PaymentMethod::tryFrom($method);
        if (! $enum) {
            throw ValidationException::withMessages([
                'payment_method' => 'Método de pago inválido.',
            ]);
        }
        if ($enum === PaymentMethod::Credit) {
            throw ValidationException::withMessages([
                'payment_method' => 'El método "credit" no aplica a pagos a proveedores.',
            ]);
        }

        return $enum->value;
    }
}
