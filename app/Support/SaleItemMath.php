<?php

namespace App\Support;

use App\Models\SaleItem;

/**
 * Math centralizada para líneas de venta.
 *
 * Regla angular: `quantity` no representa peso real cuando la línea es
 * presentación. El peso/volumen real vendido es:
 *
 *     real_content = quantity × presentation_snapshot.content
 *
 * normalizado a la unidad base (g→kg, ml→l). Para presentaciones tipo
 * "pieza" sin equivalencia en peso/volumen, real_content se interpreta
 * como `quantity` piezas y NO admite cálculos por kg ($/kg).
 *
 * Esta clase es la única fuente de verdad para:
 *   - Peso/volumen real vendido (`realContent`).
 *   - Costo total de la línea (`lineCost`).
 *   - Conversión precio-base ($/kg) → unit_price ($/presentación) al
 *     aplicar precios preferenciales (`unitPriceForBasePrice`).
 *   - Restauración de unit_price al desasignar cliente (`restoredUnitPrice`).
 *   - Fragmento SQL equivalente para agregaciones en métricas.
 */
class SaleItemMath
{
    /**
     * Peso/cantidad real efectivamente vendida.
     *
     * @return array{amount: float, unit: string, kind: string}|null
     *                                                               - kind 'weight'  → unit ∈ {kg, l}, amount es peso/volumen real.
     *                                                               - kind 'piece'   → unit = 'piece', amount es número de piezas.
     *                                                               - null si la línea legacy no es interpretable.
     */
    public static function realContent(SaleItem|array $item): ?array
    {
        $qty = (float) self::get($item, 'quantity', 0);
        $snapshot = self::snapshot($item);

        if ($snapshot && ! empty($snapshot['unit'])) {
            $unit = (string) $snapshot['unit'];
            $content = (float) ($snapshot['content'] ?? 0);

            return match ($unit) {
                'kg', 'l' => ['amount' => round($qty * $content, 3), 'unit' => $unit, 'kind' => 'weight'],
                'g' => ['amount' => round($qty * $content / 1000, 3), 'unit' => 'kg', 'kind' => 'weight'],
                'ml' => ['amount' => round($qty * $content / 1000, 3), 'unit' => 'l', 'kind' => 'weight'],
                default => ['amount' => $qty, 'unit' => 'piece', 'kind' => 'piece'],
            };
        }

        $unit = (string) (self::get($item, 'quantity_unit') ?? self::get($item, 'unit_type') ?? '');

        return match ($unit) {
            'kg', 'l' => ['amount' => round($qty, 3), 'unit' => $unit, 'kind' => 'weight'],
            'g' => ['amount' => round($qty / 1000, 3), 'unit' => 'kg', 'kind' => 'weight'],
            'ml' => ['amount' => round($qty / 1000, 3), 'unit' => 'l', 'kind' => 'weight'],
            'piece', 'cut', 'unit' => ['amount' => $qty, 'unit' => 'piece', 'kind' => 'piece'],
            default => null,
        };
    }

    /**
     * Costo total de la línea aplicando la regla:
     *   - Si la línea es weight/volume: cost_price_at_sale × real_content_in_base_unit.
     *   - Si es pieza: cost_price_at_sale × quantity.
     */
    public static function lineCost(SaleItem|array $item): float
    {
        $cost = self::get($item, 'cost_price_at_sale');
        if ($cost === null) {
            return 0.0;
        }
        $cost = (float) $cost;

        $real = self::realContent($item);
        if ($real === null) {
            return round($cost * (float) self::get($item, 'quantity', 0), 2);
        }

        return round($cost * (float) $real['amount'], 2);
    }

    /**
     * Convierte un precio base ($/kg ó $/l ó $/pieza) en el unit_price
     * que mantiene `subtotal = unit_price × quantity` correcto.
     *
     * Para presentaciones de peso/volumen: unit_price = base × content_en_unidad_base.
     * Para piezas: unit_price = base (caller decide si aplica).
     */
    public static function unitPriceForBasePrice(SaleItem|array $item, float $pricePerBaseUnit): float
    {
        $snapshot = self::snapshot($item);

        if ($snapshot && ! empty($snapshot['unit'])) {
            $unit = (string) $snapshot['unit'];
            $content = (float) ($snapshot['content'] ?? 0);

            return match ($unit) {
                'kg', 'l' => round($pricePerBaseUnit * $content, 2),
                'g', 'ml' => round($pricePerBaseUnit * $content / 1000, 2),
                default => round($pricePerBaseUnit, 2),
            };
        }

        $unit = (string) (self::get($item, 'quantity_unit') ?? self::get($item, 'unit_type') ?? '');

        return match ($unit) {
            'kg', 'l' => round($pricePerBaseUnit, 2),
            'g', 'ml' => round($pricePerBaseUnit / 1000, 4),
            default => round($pricePerBaseUnit, 2),
        };
    }

    /**
     * True si la línea representa peso o volumen y por tanto admite un
     * precio $/kg ó $/l. Las piezas/unidades sin equivalencia → false.
     */
    public static function isWeightOrVolume(SaleItem|array $item): bool
    {
        $real = self::realContent($item);

        return $real !== null && $real['kind'] === 'weight';
    }

    /**
     * unit_price restaurado al desasignar cliente:
     *   - presentación → snapshot.price (precio congelado al momento de la venta).
     *   - sin presentación → precio actual del producto.
     */
    public static function restoredUnitPrice(SaleItem|array $item, float $catalogProductPrice): float
    {
        $snapshot = self::snapshot($item);
        if ($snapshot && isset($snapshot['price'])) {
            return (float) $snapshot['price'];
        }

        return $catalogProductPrice;
    }

    /**
     * Fragmento SQL — costo total de la línea — equivalente a `lineCost()`
     * pero apto para SUM/agregaciones en queries de métricas.
     *
     * @param  string  $alias  Alias de la tabla sale_items (default 'si').
     */
    public static function lineCostSql(string $alias = 'si'): string
    {
        return "CASE
            WHEN {$alias}.cost_price_at_sale IS NULL THEN 0
            WHEN {$alias}.quantity_unit = 'unit' AND {$alias}.presentation_snapshot IS NOT NULL
                 AND {$alias}.presentation_snapshot->>'unit' IN ('kg', 'l')
                THEN {$alias}.cost_price_at_sale * {$alias}.quantity * ({$alias}.presentation_snapshot->>'content')::numeric
            WHEN {$alias}.quantity_unit = 'unit' AND {$alias}.presentation_snapshot IS NOT NULL
                 AND {$alias}.presentation_snapshot->>'unit' IN ('g', 'ml')
                THEN {$alias}.cost_price_at_sale * {$alias}.quantity * ({$alias}.presentation_snapshot->>'content')::numeric / 1000.0
            WHEN COALESCE({$alias}.quantity_unit, {$alias}.unit_type) IN ('g', 'ml')
                THEN {$alias}.cost_price_at_sale * {$alias}.quantity / 1000.0
            ELSE {$alias}.cost_price_at_sale * {$alias}.quantity
        END";
    }

    private static function snapshot(SaleItem|array $item): ?array
    {
        $raw = self::get($item, 'presentation_snapshot');
        if (is_array($raw) && ! empty($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private static function get(SaleItem|array $item, string $key, mixed $default = null): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? $default;
        }

        return $item->getAttribute($key) ?? $default;
    }
}
