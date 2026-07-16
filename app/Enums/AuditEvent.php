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

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Creó',
            self::Updated => 'Editó',
            self::Cancelled => 'Canceló',
            self::PaymentAdded => 'Registró pago',
            self::PaymentCancelled => 'Canceló pago',
            self::Merged => 'Fusionó',
        };
    }
}
