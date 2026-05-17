<?php

namespace App\Services\Ai;

use App\Models\Branch;
use App\Models\ExpenseSubcategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Valida y normaliza el JSON crudo que devuelve la IA.
 *
 * Es la línea de defensa que asegura que ningún id inventado, monto absurdo,
 * fecha futura o método de pago inválido llegue al frontend como propuesta.
 * El frontend prerellena el form pero la validación dura ocurre al guardar
 * (GastoController@store) — esto es para no mostrar valores claramente rotos.
 */
class AiExpenseProposalParser
{
    private const PAYMENT_METHODS = ['cash', 'card', 'transfer'];

    private const CONFIDENCE_LEVELS = ['alta', 'media', 'baja'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function parse(array $raw, Tenant $tenant): array
    {
        $subcategoryId = $this->resolveSubcategory($raw, $tenant);
        $branchId = $this->resolveBranch($raw, $tenant);

        return [
            'concepto' => $this->cleanString($raw['concepto'] ?? null, 160),
            'monto' => $this->cleanAmount($raw['monto'] ?? null),
            'fecha' => $this->cleanDate($raw['fecha'] ?? null),
            'expense_subcategory_id' => $subcategoryId,
            'categoria_nombre' => $this->cleanString($raw['categoria_nombre'] ?? null, 120),
            'subcategoria_nombre' => $this->cleanString($raw['subcategoria_nombre'] ?? null, 120),
            'metodo_pago' => $this->cleanPaymentMethod($raw['metodo_pago'] ?? null),
            'branch_id' => $branchId,
            'descripcion' => $this->cleanString($raw['descripcion'] ?? null, 1000),
            'confianza' => $this->cleanConfidence($raw['confianza'] ?? null) ?? 'baja',
            'confianza_por_campo' => $this->cleanConfidencePerField($raw['confianza_por_campo'] ?? []),
            'campos_faltantes' => $this->cleanStringList($raw['campos_faltantes'] ?? []),
            'alertas' => $this->cleanStringList($raw['alertas'] ?? []),
            'sugerencia_nueva_categoria' => $this->cleanSuggestion($raw['sugerencia_nueva_categoria'] ?? null),
        ];
    }

    private function resolveSubcategory(array $raw, Tenant $tenant): ?int
    {
        $id = $raw['expense_subcategory_id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }

        $exists = ExpenseSubcategory::where('id', (int) $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->exists();

        return $exists ? (int) $id : null;
    }

    private function resolveBranch(array $raw, Tenant $tenant): ?int
    {
        $id = $raw['branch_id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }

        $exists = Branch::where('id', (int) $id)
            ->where('tenant_id', $tenant->id)
            ->exists();

        return $exists ? (int) $id : null;
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

    private function cleanAmount(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $amount = round((float) $value, 2);
        if ($amount <= 0 || $amount > 99999999.99) {
            return null;
        }

        return $amount;
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
        // Permitimos pasado y hoy + 1 día (igual que la validación del form).
        if ($date->isAfter(now()->addDay()->startOfDay())) {
            return null;
        }

        return $date->toDateString();
    }

    private function cleanPaymentMethod(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $slug = strtolower(trim($value));

        return in_array($slug, self::PAYMENT_METHODS, true) ? $slug : null;
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
     * @return array<int, string>
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

    /**
     * @return array<string, mixed>|null
     */
    private function cleanSuggestion(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $tipo = $value['tipo'] ?? null;
        $nombre = $this->cleanString($value['nombre_propuesto'] ?? null, 120);
        if (! in_array($tipo, ['categoria', 'subcategoria'], true) || $nombre === null) {
            return null;
        }

        return [
            'tipo' => $tipo,
            'nombre_propuesto' => $nombre,
            'descripcion_propuesta' => $this->cleanString($value['descripcion_propuesta'] ?? null, 500),
            'categoria_padre_id' => is_numeric($value['categoria_padre_id'] ?? null)
                ? (int) $value['categoria_padre_id']
                : null,
            'razon' => $this->cleanString($value['razon'] ?? null, 300),
        ];
    }
}
