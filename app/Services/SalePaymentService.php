<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;

/**
 * Centraliza el recalculo de totales y status de una Sale tras cambios
 * en sus payments. Copia verbatim de PaymentController::recalculate
 * para no acoplar el flujo de cobro global al controller de pagos
 * individuales. Si en el futuro se unifican, es un refactor independiente.
 */
class SalePaymentService
{
    /**
     * Recalcula amount_paid, amount_pending y status de la venta según
     * los payments vigentes. Debe llamarse dentro de una DB::transaction.
     * No dispara broadcasts — el caller decide cuándo hacerlo.
     */
    public function recalculate(Sale $sale, User $user): void
    {
        $totalPaid = $sale->payments()->sum('amount');
        $pending = round((float) $sale->total - (float) $totalPaid, 2);

        $data = [
            'amount_paid' => $totalPaid,
            'amount_pending' => max($pending, 0),
        ];

        if ($pending <= 0 && $totalPaid > 0) {
            $data['status'] = SaleStatus::Completed;
            $data['completed_at'] = now();
            $data['user_id'] = $user->id;
        } elseif ($totalPaid > 0) {
            if ($sale->status === SaleStatus::Completed) {
                $data['status'] = SaleStatus::Active;
                $data['completed_at'] = null;
            }
        } elseif ($totalPaid == 0 && $sale->status !== SaleStatus::Cancelled) {
            $data['status'] = SaleStatus::Active;
            $data['completed_at'] = null;
        }

        $sale->update($data);
    }
}
