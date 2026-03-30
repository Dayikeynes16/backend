<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Pending => 'Pendiente',
            self::Completed => 'Cobrada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'blue',
            self::Pending => 'amber',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * Returns the statuses this status can transition to.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Pending, self::Completed, self::Cancelled],
            self::Pending => [self::Active, self::Cancelled],
            self::Completed => [self::Active, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions());
    }
}
