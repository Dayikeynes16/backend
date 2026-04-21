<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
            self::Transfer => 'Transferencia',
            self::Credit => 'Crédito',
        };
    }

    /**
     * Resuelve el label para cualquier slug, conocido o no.
     * Para slugs no mapeados devuelve el slug en Title Case con espacios.
     * Ejemplo: "vale_despensa" → "Vale Despensa".
     */
    public static function resolveLabel(string $slug): string
    {
        return self::tryFrom($slug)?->label()
            ?? Str::title(str_replace('_', ' ', $slug));
    }
}
