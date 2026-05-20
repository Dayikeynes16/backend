<?php

namespace App\Services\Ai;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Valida el JSON crudo que devuelve la IA al capturar una compra. Es la línea
 * de defensa que asegura que:
 *  - proveedor.id sólo exista si está en el catálogo del tenant.
 *  - product_id de cada línea sólo exista si está en el catálogo.
 *  - cantidades, precios y subtotales sean coherentes.
 *  - sugerencia_nuevo_proveedor sólo lleve nombre saneado.
 *
 * Si nada existe, deja los campos como null y el frontend muestra al usuario
 * para que confirme/corrija antes de persistir.
 */
class AiPurchaseProposalParser
{
    private const CONFIDENCE_LEVELS = ['alta', 'media', 'baja'];

    private const VALID_UNITS = ['kg', 'g', 'l', 'ml', 'pieza', 'caja', 'bulto', 'cabeza'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function parse(array $raw, Tenant $tenant): array
    {
        $proveedor = $this->resolveProvider($raw['proveedor'] ?? null, $tenant);
        $branchId = $this->resolveBranch($raw['branch_id'] ?? null, $tenant);
        $lineas = $this->parseLines($raw['lineas'] ?? [], $tenant);

        $subtotal = round(array_sum(array_map(fn ($l) => $l['subtotal'] ?? 0, $lineas)), 2);
        $totalIA = is_numeric($raw['total'] ?? null) ? round((float) $raw['total'], 2) : null;
        $totalUsed = $totalIA ?? $subtotal;

        $alertas = $this->cleanStringList($raw['alertas'] ?? []);
        if ($totalIA !== null && abs($totalIA - $subtotal) > 0.5) {
            $alertas[] = sprintf(
                'El total de la factura (%.2f) no cuadra con la suma de líneas (%.2f). Revisa.',
                $totalIA,
                $subtotal,
            );
        }

        return [
            'proveedor' => $proveedor,
            'invoice_number' => $this->cleanString($raw['invoice_number'] ?? null, 60),
            'purchased_at' => $this->cleanDate($raw['purchased_at'] ?? null),
            'branch_id' => $branchId,
            'lineas' => $lineas,
            'subtotal' => $subtotal,
            'total' => $totalUsed,
            'notas' => $this->cleanString($raw['notas'] ?? null, 1000),
            'confianza' => $this->cleanConfidence($raw['confianza'] ?? null) ?? 'baja',
            'confianza_por_campo' => $this->cleanConfidencePerField($raw['confianza_por_campo'] ?? []),
            'alertas' => $alertas,
            'sugerencia_nuevo_proveedor' => $this->cleanProviderSuggestion($raw['sugerencia_nuevo_proveedor'] ?? null),
        ];
    }

    /**
     * @return array{id: int|null, nombre: string|null}|null
     */
    private function resolveProvider(mixed $value, Tenant $tenant): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $id = $value['id'] ?? null;
        $nombre = $this->cleanString($value['nombre'] ?? null, 160);

        $resolvedId = null;
        if (is_numeric($id)) {
            $exists = Provider::query()
                ->where('id', (int) $id)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->exists();
            $resolvedId = $exists ? (int) $id : null;
        }

        if ($resolvedId === null && $nombre === null) {
            return null;
        }

        return ['id' => $resolvedId, 'nombre' => $nombre];
    }

    private function resolveBranch(mixed $value, Tenant $tenant): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $exists = Branch::where('id', (int) $value)->where('tenant_id', $tenant->id)->exists();

        return $exists ? (int) $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseLines(mixed $value, Tenant $tenant): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $concept = $this->cleanString($entry['concepto'] ?? null, 160);
            $quantity = $this->cleanPositiveNumber($entry['quantity'] ?? null, 3);
            $unit = $this->cleanUnit($entry['unit'] ?? null);
            $unitPrice = $this->cleanPositiveNumber($entry['unit_price'] ?? null, 4);
            if ($concept === null || $quantity === null || $unit === null || $unitPrice === null) {
                continue;
            }
            $productId = null;
            if (is_numeric($entry['product_id'] ?? null)) {
                $exists = Product::query()
                    ->where('id', (int) $entry['product_id'])
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->exists();
                $productId = $exists ? (int) $entry['product_id'] : null;
            }
            $out[] = [
                'product_id' => $productId,
                'concepto' => $concept,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $unitPrice,
                'subtotal' => round($quantity * $unitPrice, 2),
                'notas' => $this->cleanString($entry['notas'] ?? null, 500),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cleanProviderSuggestion(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $nombre = $this->cleanString($value['nombre_propuesto'] ?? $value['nombre'] ?? null, 160);
        if ($nombre === null) {
            return null;
        }

        return [
            'nombre_propuesto' => $nombre,
            'tipo_sugerido' => $this->cleanString($value['tipo_sugerido'] ?? $value['tipo'] ?? null, 30),
            'razon' => $this->cleanString($value['razon'] ?? null, 300),
        ];
    }

    private function cleanString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    private function cleanPositiveNumber(mixed $value, int $decimals): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $n = round((float) $value, $decimals);
        if ($n <= 0 || $n > 99999999.9999) {
            return null;
        }

        return $n;
    }

    private function cleanUnit(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $slug = strtolower(trim($value));

        return in_array($slug, self::VALID_UNITS, true) ? $slug : null;
    }

    private function cleanDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
        if ($date->isAfter(now()->addDay()->startOfDay())) {
            return null;
        }

        return $date->toDateString();
    }

    private function cleanConfidence(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $level = strtolower(trim($value));

        return in_array($level, self::CONFIDENCE_LEVELS, true) ? $level : null;
    }

    /**
     * @return array<string, string>
     */
    private function cleanConfidencePerField(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $field => $level) {
            if (! is_string($field)) {
                continue;
            }
            $clean = $this->cleanConfidence($level);
            if ($clean !== null) {
                $out[$field] = $clean;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function cleanStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($x) => is_string($x) && trim($x) !== '')
            ->map(fn ($x) => mb_substr(trim($x), 0, 200))
            ->values()
            ->all();
    }
}
