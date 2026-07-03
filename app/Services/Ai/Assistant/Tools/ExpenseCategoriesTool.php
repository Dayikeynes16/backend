<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use Illuminate\Support\Facades\DB;

/**
 * Lista el catálogo de categorías y subcategorías de gasto (tenant-wide),
 * incluyendo inactivas, descripción y cuántos gastos usan cada subcategoría.
 *
 * El conteo de gastos se acota a la sucursal del admin-sucursal (aislamiento);
 * admin-empresa ve el conteo de todas las sucursales.
 */
class ExpenseCategoriesTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_categorias_gasto';
    }

    public function description(): string
    {
        return 'Devuelve el catálogo completo de categorías y subcategorías de gasto (activas e inactivas), con su descripción y cuántos gastos usa cada una. Usar para "muéstrame mis categorías de gasto", "qué subcategorías tengo en Transporte" o "qué categorías están inactivas".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-empresa', 'admin-sucursal'];
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'include_inactive' => ['type' => 'boolean', 'description' => 'Incluir categorías/subcategorías inactivas. Default true.'],
                'category_name' => ['type' => ['string', 'null'], 'description' => 'Filtra a una sola categoría por nombre (opcional).'],
            ],
            'required' => ['include_inactive', 'category_name'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        // resolveBranch con null: admin-sucursal → su sucursal; admin-empresa → null (todas).
        $branch = $this->resolveBranch($user, null);
        $name = $params['category_name'] ?? null;
        $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null;

        return [
            'include_inactive' => (bool) ($params['include_inactive'] ?? true),
            'category_name' => $name,
            'branch_id' => $branch?->id,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        $includeInactive = $params['include_inactive'];

        $categories = ExpenseCategory::query()
            ->with(['subcategories' => fn ($q) => $q
                ->when(! $includeInactive, fn ($qq) => $qq->where('status', 'active'))
                ->orderBy('name')])
            ->when(! $includeInactive, fn ($q) => $q->where('status', 'active'))
            ->when($params['category_name'], fn ($q, $n) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($n)]))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'description', 'aliases', 'status']);

        // Conteo de gastos por subcategoría (acotado por sucursal si aplica).
        $counts = Expense::query()
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
            ->select('expense_subcategory_id', DB::raw('COUNT(*) as c'))
            ->groupBy('expense_subcategory_id')
            ->pluck('c', 'expense_subcategory_id');

        $mapped = $categories->map(function (ExpenseCategory $c) use ($counts) {
            $subs = $c->subcategories->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'status' => $s->status,
                'expense_count' => (int) ($counts[$s->id] ?? 0),
            ])->values()->all();

            return [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'status' => $c->status,
                'aliases' => $c->aliases ?? [],
                'expense_count' => array_sum(array_column($subs, 'expense_count')),
                'subcategories' => $subs,
            ];
        })->values()->all();

        $activeCount = count(array_filter($mapped, fn ($c) => $c['status'] === 'active'));
        $inactiveCount = count($mapped) - $activeCount;

        $data = [
            'categories' => $mapped,
            'total' => count($mapped),
            'active' => $activeCount,
            'inactive' => $inactiveCount,
            'include_inactive' => $includeInactive,
            'category_name' => $params['category_name'],
        ];

        $summary = $params['category_name']
            ? sprintf('Catálogo de "%s": %d categoría(s).', $params['category_name'], count($mapped))
            : sprintf('Catálogo de gastos: %d categorías (%d activas, %d inactivas).', count($mapped), $activeCount, $inactiveCount);

        return new ToolResult('expense_categories', $data, $summary, $params);
    }
}
