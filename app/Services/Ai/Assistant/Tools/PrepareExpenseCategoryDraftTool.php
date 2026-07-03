<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\Expenses\ExpenseCategoryWriter;

/**
 * Prepara un BORRADOR para dar de alta una categoría o subcategoría de gasto.
 *
 * NO la crea: persiste un `assistant_draft` (type=expense_category). Avisa si el
 * nombre ya existe (categoría) o resuelve la categoría padre (subcategoría). La
 * confirmación es una 2ª petición HTTP del usuario. admin-sucursal sólo puede si
 * su sucursal tiene habilitado el catálogo de categorías.
 */
class PrepareExpenseCategoryDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function name(): string
    {
        return 'preparar_borrador_categoria_gasto';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR para crear una categoría o subcategoría de gasto (no la crea) para que el usuario la confirme. Úsala cuando el usuario quiere agregar una categoría/subcategoría de gastos. Ejemplos: "crea la categoría Mantenimiento", "agrega la subcategoría Gasolina dentro de Transporte".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-empresa', 'admin-sucursal'];
    }

    public function authorize(User $user, array $params): bool
    {
        return ExpenseCategoryWriter::canManage($user);
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'tipo' => ['type' => ['string', 'null'], 'enum' => ['categoria', 'subcategoria'], 'description' => 'Si es una categoría nueva o una subcategoría dentro de una categoría existente.'],
                'nombre' => ['type' => ['string', 'null'], 'description' => 'Nombre de la categoría o subcategoría.'],
                'descripcion' => ['type' => ['string', 'null'], 'description' => 'Descripción opcional.'],
                'categoria_padre_nombre' => ['type' => ['string', 'null'], 'description' => 'Sólo para subcategoría: nombre de la categoría padre existente.'],
            ],
            'required' => ['tipo', 'nombre', 'descripcion', 'categoria_padre_nombre'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $tenant = app('tenant');
        $tipo = in_array($params['tipo'] ?? null, ['categoria', 'subcategoria'], true) ? $params['tipo'] : 'categoria';
        $parent = $tipo === 'subcategoria'
            ? $this->resolveParentCategory($tenant->id, $params['categoria_padre_nombre'] ?? null)
            : null;

        return [
            'tipo' => $tipo,
            'nombre' => $this->clean($params['nombre'] ?? null, 120),
            'descripcion' => $this->clean($params['descripcion'] ?? null, 500),
            'categoria_padre_nombre' => $parent?->name ?? $this->clean($params['categoria_padre_nombre'] ?? null, 120),
            'existing_category_id' => $parent?->id,
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::ExpenseCategory,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['nombre'])) {
            $missing[] = 'nombre';
        }
        if ($params['tipo'] === 'subcategoria' && empty($params['existing_category_id'])) {
            $missing[] = 'categoría padre';
        }

        $alerts = [];
        $duplicate = $this->findDuplicate($tenant->id, $params);
        if ($duplicate !== null) {
            $alerts[] = $duplicate;
        }

        $proposal = array_merge($params, ['campos_faltantes' => $missing, 'alertas' => $alerts]);
        $this->drafts->markReady($draft, $proposal);

        $data = $this->buildCard($draft->fresh(), $proposal);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de categoría. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'expense_category',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'summary' => 'Borrador de categoría preparado. Espera a que el usuario lo confirme con el botón; tú no puedes crearla.',
            ],
        );
    }

    private function resolveParentCategory(int $tenantId, ?string $name): ?ExpenseCategory
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return ExpenseCategory::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first(['id', 'name']);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function findDuplicate(int $tenantId, array $params): ?string
    {
        $name = trim((string) ($params['nombre'] ?? ''));
        if ($name === '') {
            return null;
        }

        if ($params['tipo'] === 'categoria') {
            $exists = ExpenseCategory::query()
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            return $exists ? 'Ya existe una categoría con el nombre "'.$name.'".' : null;
        }

        if (! empty($params['existing_category_id'])) {
            $exists = ExpenseSubcategory::query()
                ->where('tenant_id', $tenantId)
                ->where('expense_category_id', $params['existing_category_id'])
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            return $exists ? 'Ya existe una subcategoría "'.$name.'" en esa categoría.' : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function buildCard(AssistantDraft $draft, array $proposal): array
    {
        $categories = ExpenseCategory::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name'])
            ->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        return [
            'draft_id' => $draft->id,
            'draft_type' => 'expense_category',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'tipo' => $proposal['tipo'] ?? 'categoria',
                'nombre' => $proposal['nombre'] ?? null,
                'descripcion' => $proposal['descripcion'] ?? null,
                'existing_category_id' => $proposal['existing_category_id'] ?? null,
                'categoria_padre_nombre' => $proposal['categoria_padre_nombre'] ?? null,
            ],
            'missing_fields' => $proposal['campos_faltantes'] ?? [],
            'warnings' => $proposal['alertas'] ?? [],
            'options' => [
                'categories' => $categories,
            ],
        ];
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }
}
