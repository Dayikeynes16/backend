<?php

namespace App\Enums;

enum ProviderType: string
{
    case Ganadero = 'ganadero';
    case MayoristaCarne = 'mayorista_carne';
    case Insumos = 'insumos';
    case Servicios = 'servicios';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Ganadero => 'Ganadero',
            self::MayoristaCarne => 'Mayorista de carne',
            self::Insumos => 'Insumos',
            self::Servicios => 'Servicios',
            self::Otro => 'Otro',
        };
    }
}
