<?php

namespace App\Services;

use App\Models\CashRegisterShift;

/**
 * Fuente única del "veredicto" de un corte: interpreta las diferencias por
 * método (efectivo/tarjeta/transferencia) en términos NETOS del turno.
 *
 * Distingue la compensación: si falta efectivo pero sobra la misma cantidad en
 * tarjeta, la caja cuadra en total aunque un método individual esté descuadrado
 * (típico cobro registrado con el método equivocado).
 *
 * Opera solo sobre campos ya persistidos en el shift, así que reinterpreta
 * cortes históricos sin recálculo. Consumido por ShiftReportMessageService
 * (texto de WhatsApp) y por los controladores de corte (prop Inertia 'verdict').
 *
 * El criterio de "método aplicable" y las derivaciones (expected/declared/diff)
 * son idénticos a los de las tablas Vue Corte.vue / Cortes/Show.vue, para que el
 * total neto siempre iguale la suma de las filas visibles.
 */
class ShiftVerdictService
{
    /**
     * @var array<int,array{key:string,label:string,declaredField:string,diffField:string,expectedField:string,totalField:string}>
     */
    private const METHODS = [
        ['key' => 'cash', 'label' => 'efectivo', 'declaredField' => 'declared_amount', 'diffField' => 'difference', 'expectedField' => 'expected_amount', 'totalField' => 'total_cash'],
        ['key' => 'card', 'label' => 'tarjeta', 'declaredField' => 'declared_card', 'diffField' => 'difference_card', 'expectedField' => 'total_card', 'totalField' => 'total_card'],
        ['key' => 'transfer', 'label' => 'transferencia', 'declaredField' => 'declared_transfer', 'diffField' => 'difference_transfer', 'expectedField' => 'total_transfer', 'totalField' => 'total_transfer'],
    ];

    /**
     * @return array{
     *   status: string, tone: string, headline: string, detail: ?string,
     *   expected_total: float, declared_total: float, total_diff: float,
     *   by_method: array<int,array{key:string,label:string,expected:float,declared:float,diff:float,declared_is_null:bool}>
     * }
     */
    public function build(CashRegisterShift $shift): array
    {
        $byMethod = [];
        foreach (self::METHODS as $m) {
            $declaredRaw = $shift->{$m['declaredField']};
            $total = round((float) $shift->{$m['totalField']}, 2);

            if ($declaredRaw === null && $total <= 0.0) {
                continue; // método no aplicable
            }

            $byMethod[] = [
                'key' => $m['key'],
                'label' => $m['label'],
                'expected' => round((float) $shift->{$m['expectedField']}, 2),
                'declared' => round((float) ($declaredRaw ?? $shift->{$m['totalField']}), 2),
                'diff' => round((float) $shift->{$m['diffField']}, 2),
                'declared_is_null' => $declaredRaw === null,
            ];
        }

        $expectedTotal = round(array_sum(array_column($byMethod, 'expected')), 2);
        $declaredTotal = round(array_sum(array_column($byMethod, 'declared')), 2);
        $totalDiff = round($declaredTotal - $expectedTotal, 2);

        $offMethods = array_values(array_filter($byMethod, fn ($x) => abs($x['diff']) > 0.0));
        $anyMethodOff = count($offMethods) > 0;
        $hasPositive = count(array_filter($offMethods, fn ($x) => $x['diff'] > 0.0)) > 0;
        $hasNegative = count(array_filter($offMethods, fn ($x) => $x['diff'] < 0.0)) > 0;
        $signsMixed = $hasPositive && $hasNegative;
        $allUndeclared = count(array_filter($byMethod, fn ($x) => ! $x['declared_is_null'])) === 0;

        [$status, $tone, $headline, $detail] = $this->verdict($byMethod, $totalDiff, $anyMethodOff, $signsMixed, $allUndeclared);

        return [
            'status' => $status,
            'tone' => $tone,
            'headline' => $headline,
            'detail' => $detail,
            'expected_total' => $expectedTotal,
            'declared_total' => $declaredTotal,
            'total_diff' => $totalDiff,
            'by_method' => $byMethod,
        ];
    }

    /**
     * @param  array<int,array{key:string,label:string,expected:float,declared:float,diff:float,declared_is_null:bool}>  $byMethod
     * @return array{0:string,1:string,2:string,3:?string}
     */
    private function verdict(array $byMethod, float $totalDiff, bool $anyMethodOff, bool $signsMixed, bool $allUndeclared): array
    {
        if ($allUndeclared) {
            return ['undeclared', 'neutral', '📋 Cierre sin conteo declarado.', null];
        }

        if (! $anyMethodOff) {
            return ['balanced', 'ok', '✅ Caja cuadrada — sin diferencias.', null];
        }

        if (abs($totalDiff) <= 0.0) {
            return [
                'cross_balanced',
                'warn',
                '⚖️ La caja cuadra en total, pero hay diferencias cruzadas entre métodos.',
                $this->crossDetail($byMethod).' — posible cobro registrado con otro método.',
            ];
        }

        $word = $totalDiff < 0.0 ? 'Faltante' : 'Sobrante';
        $headline = '⚠️ '.$word.' total de '.$this->money(abs($totalDiff)).'.';

        $detail = null;
        if ($signsMixed) {
            $realWord = $totalDiff < 0.0 ? 'faltante' : 'sobrante';
            $detail = 'El '.$realWord.' real es '.$this->money(abs($totalDiff)).': '.$this->crossDetail($byMethod).'.';
        }

        return ['net_off', 'bad', $headline, $detail];
    }

    /**
     * "faltan $X en efectivo, sobran $Y en tarjeta" — negativos primero.
     *
     * @param  array<int,array{key:string,label:string,expected:float,declared:float,diff:float,declared_is_null:bool}>  $byMethod
     */
    private function crossDetail(array $byMethod): string
    {
        $parts = [];
        foreach ($byMethod as $m) {
            if ($m['diff'] < 0.0) {
                $parts[] = 'faltan '.$this->money(abs($m['diff'])).' en '.$m['label'];
            }
        }
        foreach ($byMethod as $m) {
            if ($m['diff'] > 0.0) {
                $parts[] = 'sobran '.$this->money($m['diff']).' en '.$m['label'];
            }
        }

        return implode(', ', $parts);
    }

    private function money(float $value): string
    {
        return '$'.number_format($value, 2, '.', ',');
    }
}
