<?php

namespace App\Enums;

enum PurchaseProductCategory: string
{
    case Res = 'res';
    case Cerdo = 'cerdo';
    case Pollo = 'pollo';
    case Insumos = 'insumos';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Res => 'Res',
            self::Cerdo => 'Cerdo',
            self::Pollo => 'Pollo',
            self::Insumos => 'Insumos',
            self::Otro => 'Otro',
        };
    }
}
