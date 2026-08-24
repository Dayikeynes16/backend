<?php

namespace App\Enums;

enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Cancelled = 'cancelled';
    case PaymentAdded = 'payment_added';
    case PaymentCancelled = 'payment_cancelled';
    case Merged = 'merged';

    // ── Movimientos de una venta ya cobrada (2026-08-21) ────────────────
    // Las cuatro formas de sacarle dinero a una venta. `payment_added` y
    // `cancelled`, de arriba, también aplican a ventas y se reutilizan.
    case ItemAdded = 'item_added';
    case ItemUpdated = 'item_updated';
    case ItemRemoved = 'item_removed';
    case PaymentUpdated = 'payment_updated';
    case PaymentDeleted = 'payment_deleted';
    case Reopened = 'reopened';
    case CustomerAssigned = 'customer_assigned';
    case CustomerRemoved = 'customer_removed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Creó',
            self::Updated => 'Editó',
            self::Cancelled => 'Canceló',
            self::PaymentAdded => 'Registró pago',
            self::PaymentCancelled => 'Canceló pago',
            self::Merged => 'Fusionó',
            self::ItemAdded => 'Agregó producto',
            self::ItemUpdated => 'Cambió producto',
            self::ItemRemoved => 'Quitó producto',
            self::PaymentUpdated => 'Editó pago',
            self::PaymentDeleted => 'Borró pago',
            self::Reopened => 'Reabrió',
            self::CustomerAssigned => 'Asignó cliente',
            self::CustomerRemoved => 'Quitó cliente',
        };
    }

    /**
     * Eventos que este módulo considera movimientos sobre una venta ya cobrada.
     * Los de compras y gastos comparten tabla pero no esta pantalla.
     *
     * @return list<string>
     */
    public static function saleMovements(): array
    {
        return array_map(fn (self $e) => $e->value, [
            self::ItemAdded, self::ItemUpdated, self::ItemRemoved,
            // `payment_added` queda fuera a propósito: hoy solo lo escribe el
            // módulo de compras, y registrar cada cobro llenaría la pantalla con
            // todas las ventas del día — el ruido que existe para evitar.
            self::PaymentUpdated, self::PaymentDeleted,
            self::Cancelled, self::Reopened,
            self::CustomerAssigned, self::CustomerRemoved,
        ]);
    }

    /**
     * Un movimiento no monetario se ve en la lista pero queda fuera del neto:
     * pasar una venta a fiado saca el dinero del efectivo del día sin perderlo,
     * y sumarlo como pérdida daría un total que no ocurrió.
     */
    public function isMonetary(): bool
    {
        return ! in_array($this, [self::Reopened, self::CustomerAssigned, self::CustomerRemoved], true);
    }
}
