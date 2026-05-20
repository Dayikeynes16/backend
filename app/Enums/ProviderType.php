<?php

namespace App\Enums;

enum ProviderType: string
{
    case Ganadero = 'ganadero';
    case MayoristaCarne = 'mayorista_carne';
    case Insumos = 'insumos';
    case Servicios = 'servicios';
    case Otro = 'otro';
}
