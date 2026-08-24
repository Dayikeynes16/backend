<?php

namespace App\Observers;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\AuditLogger;

/**
 * Registra en Movimientos las transiciones de estado que mueven dinero.
 *
 * Va en un observer y no en los controladores porque cancelar o reabrir una venta
 * se hace hoy desde cuatro sitios distintos —`Sucursal\WorkbenchController`,
 * `Caja\WorkbenchController`, y `cancel`/`reopen` del hub—, y una quinta ruta
 * futura se olvidaría de registrar. El modelo es el único punto por el que pasan
 * todas.
 *
 * Solo mira `status`: el resto de campos de una venta cambia constantemente por
 * recálculos de pagos y no es lo que se está vigilando.
 */
class SaleAuditObserver
{
    public function __construct(private AuditLogger $audit) {}

    public function updated(Sale $sale): void
    {
        if (! $sale->wasChanged('status')) {
            return;
        }

        $before = $sale->getOriginal('status');
        $before = $before instanceof SaleStatus ? $before : SaleStatus::tryFrom((string) $before);
        $after = $sale->status;

        if ($after === SaleStatus::Cancelled) {
            // El motivo ya vive en la propia venta; se copia al registro para que
            // Movimientos no dependa de que la venta siga existiendo tal cual.
            $this->audit->logSaleCancelled($sale, $sale->cancel_reason);

            return;
        }

        // Reabrir: una venta que ya estaba cobrada vuelve a ser editable. No mueve
        // dinero por sí sola —habilita moverlo—, así que su efecto es nulo.
        if ($before === SaleStatus::Completed && $after === SaleStatus::Active) {
            $this->audit->logSaleReopened($sale);
        }
    }
}
