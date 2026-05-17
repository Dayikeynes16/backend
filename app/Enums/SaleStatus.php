<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Fulfilled = 'fulfilled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Pending => 'Pendiente',
            self::Completed => 'Cobrada',
            self::Cancelled => 'Cancelada',
            self::Fulfilled => 'Cumplida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'blue',
            self::Pending => 'amber',
            self::Completed => 'green',
            self::Cancelled => 'red',
            self::Fulfilled => 'emerald',
        };
    }

    /**
     * Returns the statuses this status can transition to.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Pending, self::Completed, self::Cancelled],
            self::Pending => [self::Active, self::Cancelled, self::Fulfilled],
            self::Completed => [self::Active, self::Cancelled],
            self::Cancelled => [],
            self::Fulfilled => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions());
    }
}
