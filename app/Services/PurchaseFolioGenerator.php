<?php

namespace App\Services;

use App\Models\Purchase;

/**
 * Genera folios CMP-YYYY-NNNNN únicos por tenant. Estrategia simple:
 * busca el último folio del año en curso para el tenant y suma 1.
 *
 * Si en algún momento esto choca por concurrencia, hay un UNIQUE
 * (tenant_id, folio) en la BD que rebotará — el caller debe reintentar.
 * En la práctica las compras se capturan a velocidad humana, así que no
 * vale la pena meter una secuencia atómica todavía.
 */
final class PurchaseFolioGenerator
{
    public function nextFolio(int $tenantId): string
    {
        $year = now()->format('Y');
        $prefix = 'CMP-'.$year.'-';

        $last = Purchase::withTrashed()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('folio', 'like', $prefix.'%')
            ->orderByDesc('folio')
            ->value('folio');

        $nextNum = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $nextNum = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);
    }
}
