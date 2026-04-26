<?php

namespace App\Services;

use App\Models\SaleItem;

/**
 * Centraliza el render de líneas de venta. Resuelve la coexistencia de:
 *
 *  - Filas nuevas (post fase 1) con presentation_snapshot, sale_mode_at_sale
 *    y quantity_unit. Estas son la única fuente de verdad cuando existen.
 *  - Filas viejas con solo unit_type + quantity heredadas del producto. Se
 *    leen con fallback al contrato legado.
 *
 * Una línea de venta = un solo objeto SaleItem o array equivalente.
 */
class SaleItemFormatter
{
    /**
     * "Queso — medio queso (500 g)" o "Queso" según haya snapshot.
     */
    public function displayName(SaleItem|array $item): string
    {
        $snapshot = $this->snapshot($item);
        $name = (string) $this->get($item, 'product_name', '');

        if ($snapshot && ! empty($snapshot['content']) && ! empty($snapshot['unit'])) {
            $content = $this->formatNumber((float) $snapshot['content'], $snapshot['unit']);

            return $name.' ('.$content.' '.$snapshot['unit'].')';
        }

        return $name;
    }

    /**
     * Cantidad legible para tabla/ticket. Ejemplos:
     *   weight (kg)        →  "1.250 kg"
     *   presentation       →  "× 2"
     *   pieza directa      →  "3 pz"
     *   legacy unit_type   →  fallback razonable
     */
    public function displayQuantity(SaleItem|array $item): string
    {
        $qty = (float) $this->get($item, 'quantity', 0);
        $unit = $this->effectiveUnit($item);

        return match ($unit) {
            'unit' => '× '.$this->formatInt($qty),
            'kg' => number_format($qty, 3, '.', '').' kg',
            'g' => number_format($qty, 0, '.', '').' g',
            'piece', 'cut' => $this->formatInt($qty).' pz',
            default => trim($this->formatNumber($qty, $unit).' '.$unit),
        };
    }

    /**
     * Peso/cantidad real efectivamente vendida. Útil para reportes:
     * 2 medios quesos → ['amount' => 1.0, 'unit' => 'kg']
     * 1.250 kg directo → ['amount' => 1.250, 'unit' => 'kg']
     * 3 piezas → ['amount' => 3.0, 'unit' => 'piece']
     *
     * Devuelve null si no se puede inferir (línea legacy sin snapshot ni
     * unit_type interpretable).
     */
    public function realContent(SaleItem|array $item): ?array
    {
        $qty = (float) $this->get($item, 'quantity', 0);
        $snapshot = $this->snapshot($item);

        if ($snapshot && ! empty($snapshot['content']) && ! empty($snapshot['unit'])) {
            // N presentaciones × content c/u, normalizado a unidad base si aplica.
            $rawAmount = $qty * (float) $snapshot['content'];
            [$amount, $unit] = $this->normalize($rawAmount, $snapshot['unit']);

            return ['amount' => $amount, 'unit' => $unit];
        }

        $unit = $this->effectiveUnit($item);

        // 'unit' sin snapshot: solo sabemos cuántas "unidades" se vendieron.
        if ($unit === 'unit') {
            return ['amount' => $qty, 'unit' => 'unit'];
        }

        if (in_array($unit, ['kg', 'g', 'ml', 'l', 'piece', 'cut'], true)) {
            return ['amount' => $qty, 'unit' => $unit];
        }

        return null;
    }

    /**
     * Modo efectivo: 'presentation' | 'weight' | 'piece' | 'unknown'.
     * Funciona tanto para filas nuevas como legacy.
     */
    public function saleMode(SaleItem|array $item): string
    {
        $explicit = $this->get($item, 'sale_mode_at_sale');
        if ($explicit) {
            return $explicit;
        }
        if ($this->snapshot($item)) {
            return 'presentation';
        }
        $legacy = (string) $this->get($item, 'unit_type', '');

        return match ($legacy) {
            'kg', 'g', 'ml', 'l' => 'weight',
            'piece', 'cut' => 'piece',
            default => 'unknown',
        };
    }

    private function effectiveUnit(SaleItem|array $item): string
    {
        return (string) ($this->get($item, 'quantity_unit')
            ?? $this->get($item, 'unit_type')
            ?? '');
    }

    private function snapshot(SaleItem|array $item): ?array
    {
        $raw = $this->get($item, 'presentation_snapshot');
        if (is_array($raw) && ! empty($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function get(SaleItem|array $item, string $key, mixed $default = null): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? $default;
        }

        return $item->getAttribute($key) ?? $default;
    }

    private function formatInt(float $n): string
    {
        return $n == (int) $n ? (string) (int) $n : number_format($n, 2, '.', '');
    }

    private function formatNumber(float $n, ?string $unit): string
    {
        return match ($unit) {
            'kg', 'l' => number_format($n, 3, '.', ''),
            'g', 'ml' => number_format($n, 0, '.', ''),
            default => $n == (int) $n ? (string) (int) $n : number_format($n, 2, '.', ''),
        };
    }

    /**
     * Sube de g→kg / ml→l si el monto >= 1000, para reportes legibles.
     */
    private function normalize(float $amount, string $unit): array
    {
        if ($unit === 'g' && $amount >= 1000) {
            return [round($amount / 1000, 3), 'kg'];
        }
        if ($unit === 'ml' && $amount >= 1000) {
            return [round($amount / 1000, 3), 'l'];
        }

        return [round($amount, 3), $unit];
    }
}
