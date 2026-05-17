<?php

namespace App\Services\Ai;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Tenant;

/**
 * Valida y normaliza la respuesta cruda de OpenAI para "Crear categoría con IA".
 *
 * Discrimina entre cuatro acciones: crear_categoria, usar_existente,
 * crear_subcategoria, necesita_aclaracion.
 *
 * Si la IA inventa un id (categoria_similar_existente, categoria_padre,
 * subcategoria_similar_existente) que no pertenece al tenant o no respeta la
 * jerarquía esperada, degradamos silenciosamente a crear_categoria con una
 * alerta para que el usuario vea qué pasó sin bloquear el flujo.
 */
class AiCategoryProposalParser
{
    private const VALID_ACTIONS = [
        'crear_categoria',
        'usar_existente',
        'crear_subcategoria',
        'necesita_aclaracion',
    ];

    private const CONFIDENCE_LEVELS = ['alta', 'media', 'baja'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function parse(array $raw, Tenant $tenant): array
    {
        $action = $this->cleanAction($raw['accion_sugerida'] ?? null);
        $existing = $this->resolveExistingCategory($raw['categoria_similar_existente'] ?? null, $tenant);
        $parent = $this->resolveExistingCategory($raw['categoria_padre'] ?? null, $tenant);
        $alerts = [];

        // usar_existente requiere id válido — si no, crear nueva.
        if ($action === 'usar_existente' && $existing === null) {
            $action = 'crear_categoria';
            $alerts[] = 'La IA propuso reutilizar una categoría que no existe en tu catálogo; se pasó a crear nueva.';
        }

        // crear_subcategoria requiere parent válido — si no, crear nueva.
        if ($action === 'crear_subcategoria' && $parent === null) {
            $action = 'crear_categoria';
            $alerts[] = 'La IA sugirió agregar una subcategoría a una categoría que no existe; se pasó a crear nueva.';
        }

        // Una subcategoría similar bajo el parent es válida sólo si pertenece
        // a ese parent (no a otra categoría).
        $similarSubcategory = null;
        if ($action === 'crear_subcategoria' && $parent !== null) {
            $similarSubcategory = $this->resolveSimilarSubcategory(
                $raw['subcategoria_similar_existente'] ?? null,
                $tenant,
                $parent['id'],
            );
        }

        $subcategories = $this->cleanSubcategories($raw['subcategorias_sugeridas'] ?? []);

        // En crear_subcategoria, la IA puede usar la forma singular
        // "subcategoria_propuesta" en vez del array. Lo aceptamos como
        // sinónimo del primer item de subcategorias_sugeridas.
        if ($action === 'crear_subcategoria' && $subcategories === [] && isset($raw['subcategoria_propuesta'])) {
            $subcategories = $this->cleanSubcategories([$raw['subcategoria_propuesta']]);
        }

        return [
            'action' => $action,
            'category' => $this->cleanCategory($raw),
            'existing_category' => $existing,
            'parent_category' => $action === 'crear_subcategoria' ? $parent : null,
            'similar_subcategory' => $similarSubcategory,
            'improvements' => $action === 'usar_existente'
                ? $this->cleanImprovements($raw['mejoras_sugeridas'] ?? null)
                : null,
            'subcategories' => $subcategories,
            'confidence' => $this->cleanConfidence($raw['confianza'] ?? null) ?? 'baja',
            'missing_questions' => $this->cleanStringList($raw['preguntas_faltantes'] ?? [], 300, 5),
            'alerts' => $alerts,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSimilarSubcategory(mixed $value, Tenant $tenant, int $parentId): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $id = $value['id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }

        // Debe pertenecer al tenant Y al parent propuesto. Una subcategoría
        // de OTRA categoría no aplica como "similar" en este contexto.
        $sub = ExpenseSubcategory::where('id', (int) $id)
            ->where('tenant_id', $tenant->id)
            ->where('expense_category_id', $parentId)
            ->where('status', 'active')
            ->first(['id', 'name']);

        if (! $sub) {
            return null;
        }

        return [
            'id' => $sub->id,
            'name' => $sub->name,
            'reason' => $this->cleanString($value['razon'] ?? null, 300),
        ];
    }

    private function cleanAction(mixed $value): string
    {
        if (! is_string($value)) {
            return 'crear_categoria';
        }
        $clean = strtolower(trim($value));

        return in_array($clean, self::VALID_ACTIONS, true) ? $clean : 'crear_categoria';
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanCategory(array $raw): array
    {
        return [
            'name' => $this->cleanString($raw['nombre_categoria'] ?? null, 120),
            'description' => $this->cleanString($raw['descripcion'] ?? null, 500),
            'aliases' => $this->cleanStringList($raw['aliases'] ?? [], 60, 10),
            'includes' => $this->cleanStringList($raw['incluye'] ?? $raw['includes'] ?? [], 80, 15),
            'excludes' => $this->cleanStringList($raw['no_incluye'] ?? $raw['excludes'] ?? [], 80, 15),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveExistingCategory(mixed $value, Tenant $tenant): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $id = $value['id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }

        $cat = ExpenseCategory::where('id', (int) $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first(['id', 'name']);

        if (! $cat) {
            return null;
        }

        return [
            'id' => $cat->id,
            'name' => $cat->name,
            'reason' => $this->cleanString($value['razon'] ?? null, 300),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cleanImprovements(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return [
            'description' => $this->cleanString($value['descripcion'] ?? null, 500),
            'aliases_to_add' => $this->cleanStringList($value['aliases_a_agregar'] ?? $value['aliases'] ?? [], 60, 10),
            'includes_to_add' => $this->cleanStringList($value['includes_a_agregar'] ?? $value['incluye'] ?? $value['includes'] ?? [], 80, 15),
            'excludes_to_add' => $this->cleanStringList($value['excludes_a_agregar'] ?? $value['no_incluye'] ?? $value['excludes'] ?? [], 80, 15),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cleanSubcategories(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $sub) {
            if (! is_array($sub)) {
                continue;
            }
            $name = $this->cleanString($sub['nombre'] ?? null, 120);
            if ($name === null) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'description' => $this->cleanString($sub['descripcion'] ?? null, 500),
                'aliases' => $this->cleanStringList($sub['aliases'] ?? [], 60, 10),
                'includes' => $this->cleanStringList($sub['incluye'] ?? $sub['includes'] ?? [], 80, 15),
                'excludes' => $this->cleanStringList($sub['no_incluye'] ?? $sub['excludes'] ?? [], 80, 15),
            ];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
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

    private function cleanConfidence(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $level = strtolower(trim($value));

        return in_array($level, self::CONFIDENCE_LEVELS, true) ? $level : null;
    }

    /**
     * @return array<int, string>
     */
    private function cleanStringList(mixed $value, int $maxItemLength = 200, int $maxItems = 20): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($x) => is_string($x) && trim($x) !== '')
            ->map(fn ($x) => mb_substr(trim($x), 0, $maxItemLength))
            ->unique(fn ($x) => mb_strtolower($x))
            ->take($maxItems)
            ->values()
            ->all();
    }
}
