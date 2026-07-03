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
 * Prepara un BORRADOR para EDITAR una categoría o subcategoría de gasto.
 *
 * NO la edita: persiste un `assistant_draft` (type=expense_category_edit) con el
 * objetivo y los cambios propuestos (nombre, descripción, estado). La
 * confirmación es una 2ª petición HTTP del usuario. Gateado por el toggle de
 * sucursal (mismos permisos que crear).
 */
class PrepareExpenseCategoryEditDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function name(): string
    {
        return 'editar_categoria_gasto';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR para EDITAR una categoría o subcategoría de gasto existente (renombrar, cambiar descripción, activar/inactivar). No la modifica hasta que el usuario confirme. Ejemplos: "renombra la categoría Transporte a Logística", "desactiva la subcategoría Gasolina", "cambia la descripción de Mantenimiento".';
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
                'tipo' => ['type' => ['string', 'null'], 'enum' => ['categoria', 'subcategoria'], 'description' => 'Si se edita una categoría o una subcategoría.'],
                'nombre_actual' => ['type' => ['string', 'null'], 'description' => 'Nombre actual de la categoría/subcategoría a editar.'],
                'categoria_padre_nombre' => ['type' => ['string', 'null'], 'description' => 'Sólo para subcategoría: nombre de su categoría padre (para desambiguar).'],
                'nuevo_nombre' => ['type' => ['string', 'null'], 'description' => 'Nuevo nombre, si se quiere renombrar.'],
                'nueva_descripcion' => ['type' => ['string', 'null'], 'description' => 'Nueva descripción, si se quiere cambiar.'],
                'nuevo_estado' => ['type' => ['string', 'null'], 'enum' => ['activo', 'inactivo'], 'description' => 'Nuevo estado, si se quiere activar/inactivar.'],
            ],
            'required' => ['tipo', 'nombre_actual', 'categoria_padre_nombre', 'nuevo_nombre', 'nueva_descripcion', 'nuevo_estado'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $tenant = app('tenant');
        $tipo = in_array($params['tipo'] ?? null, ['categoria', 'subcategoria'], true) ? $params['tipo'] : 'categoria';
        $nombreActual = $this->clean($params['nombre_actual'] ?? null, 120);

        $target = $tipo === 'categoria'
            ? $this->resolveCategory($tenant->id, $nombreActual)
            : $this->resolveSubcategory($tenant->id, $nombreActual, $params['categoria_padre_nombre'] ?? null);

        return [
            'target_type' => $tipo,
            'target_id' => $target?->id,
            'nombre_actual' => $nombreActual,
            'current_name' => $target?->name,
            'current_description' => $target?->description,
            'current_status' => $target?->status,
            'new_name' => $this->clean($params['nuevo_nombre'] ?? null, 120),
            'new_description' => $this->clean($params['nueva_descripcion'] ?? null, 500),
            'new_status' => $this->cleanStatus($params['nuevo_estado'] ?? null),
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::ExpenseCategoryEdit,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['target_id'])) {
            $missing[] = $params['target_type'] === 'subcategoria' ? 'subcategoría' : 'categoría';
        }

        // Valores efectivos: el cambio propuesto, o el valor actual si no se pidió cambiar.
        $effective = [
            'name' => $params['new_name'] ?? $params['current_name'],
            'description' => $params['new_description'] ?? $params['current_description'],
            'status' => $params['new_status'] ?? $params['current_status'],
        ];

        $proposal = array_merge($params, ['campos_faltantes' => $missing, 'effective' => $effective]);
        $this->drafts->markReady($draft, $proposal);

        $data = $this->buildCard($draft->fresh(), $params, $effective, $missing);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé los cambios de la categoría. Están pendientes de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'expense_category_edit',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'summary' => empty($params['target_id'])
                    ? 'No encontré esa categoría/subcategoría. Pide al usuario que confirme el nombre exacto.'
                    : 'Borrador de edición preparado. Espera a que el usuario lo confirme con el botón; tú no puedes editarla.',
            ],
        );
    }

    private function resolveCategory(int $tenantId, ?string $name): ?ExpenseCategory
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return ExpenseCategory::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function resolveSubcategory(int $tenantId, ?string $name, ?string $parentName): ?ExpenseSubcategory
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $parentId = null;
        if (trim((string) $parentName) !== '') {
            $parentId = $this->resolveCategory($tenantId, $parentName)?->id;
        }

        return ExpenseSubcategory::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($parentId, fn ($q) => $q->where('expense_category_id', $parentId))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $effective
     * @param  array<int, string>  $missing
     * @return array<string, mixed>
     */
    private function buildCard(AssistantDraft $draft, array $params, array $effective, array $missing): array
    {
        return [
            'draft_id' => $draft->id,
            'draft_type' => 'expense_category_edit',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'target_type' => $params['target_type'],
                'target_id' => $params['target_id'],
                'nombre_actual' => $params['nombre_actual'],
                'current_name' => $params['current_name'],
                'current_status' => $params['current_status'],
                'name' => $effective['name'],
                'description' => $effective['description'],
                'status' => $effective['status'],
            ],
            'missing_fields' => $missing,
            'warnings' => [],
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

    private function cleanStatus(mixed $value): ?string
    {
        return match ($value) {
            'activo', 'active' => 'active',
            'inactivo', 'inactive' => 'inactive',
            default => null,
        };
    }
}
