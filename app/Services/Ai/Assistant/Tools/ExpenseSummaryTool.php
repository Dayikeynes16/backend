<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use Illuminate\Support\Facades\DB;

class ExpenseSummaryTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_gastos';
    }

    public function description(): string
    {
        return 'Devuelve el total de gastos, conteo, top de subcategorías y desglose por método de pago en un periodo. Puede filtrar por una categoría o subcategoría específica. Usar para "¿cuánto gasté esta semana?", "gastos de Transporte este mes" o "cuánto llevo en Gasolina".';
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
                'scope' => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'],
                ],
                'date_from' => ['type' => ['string', 'null']],
                'date_to' => ['type' => ['string', 'null']],
                'branch_name' => ['type' => ['string', 'null']],
                'category_name' => ['type' => ['string', 'null'], 'description' => 'Filtra por una categoría de gasto específica (nombre).'],
                'subcategory_name' => ['type' => ['string', 'null'], 'description' => 'Filtra por una subcategoría específica (nombre).'],
                'top_limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'description' => 'Cuántas subcategorías top devolver. Default 5.',
                ],
            ],
            'required' => ['scope', 'date_from', 'date_to', 'branch_name', 'category_name', 'subcategory_name', 'top_limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $scope = (string) ($params['scope'] ?? 'today');
        [$from, $to] = $this->resolveDateRange($scope, $params['date_from'] ?? null, $params['date_to'] ?? null);
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $limit = (int) ($params['top_limit'] ?? 5);
        $limit = max(1, min(10, $limit));

        $tenantId = app('tenant')->id;
        $categoryName = $this->cleanName($params['category_name'] ?? null);
        $categoryId = null;
        $categoryFound = null;
        if ($categoryName !== null) {
            $categoryId = ExpenseCategory::query()
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])
                ->value('id');
            $categoryFound = $categoryId !== null;
        }

        $subName = $this->cleanName($params['subcategory_name'] ?? null);
        $subId = null;
        $subFound = null;
        if ($subName !== null) {
            $subId = ExpenseSubcategory::query()
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($subName)])
                ->when($categoryId, fn ($q) => $q->where('expense_category_id', $categoryId))
                ->value('id');
            $subFound = $subId !== null;
        }

        return [
            'scope' => $scope,
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'top_limit' => $limit,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'category_found' => $categoryFound,
            'subcategory_id' => $subId,
            'subcategory_name' => $subName,
            'subcategory_found' => $subFound,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        // BelongsToTenant ya filtra por tenant_id automáticamente.
        $base = function () use ($params) {
            $q = Expense::query()
                ->when($params['branch_id'], fn ($qq) => $qq->where('branch_id', $params['branch_id']))
                ->whereBetween('expense_at', [$params['date_from'].' 00:00:00', $params['date_to'].' 23:59:59']);

            if ($params['subcategory_id']) {
                $q->where('expense_subcategory_id', $params['subcategory_id']);
            } elseif ($params['category_id']) {
                $q->whereHas('subcategory', fn ($sq) => $sq->where('expense_category_id', $params['category_id']));
            }

            // Nombre dado pero no encontrado en el catálogo → cero resultados (honesto).
            if (($params['category_name'] && ! $params['category_found'])
                || ($params['subcategory_name'] && ! $params['subcategory_found'])) {
                $q->whereRaw('1 = 0');
            }

            return $q;
        };

        $total = (float) $base()->sum('amount');
        $count = (int) $base()->count();

        $topRows = $base()
            ->select('expense_subcategory_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('expense_subcategory_id')
            ->orderByDesc('total')
            ->limit($params['top_limit'])
            ->with(['subcategory:id,expense_category_id,name', 'subcategory.category:id,name'])
            ->get()
            ->map(fn ($r) => [
                'subcategory' => $r->subcategory?->name,
                'category' => $r->subcategory?->category?->name,
                'total' => (float) $r->total,
                'count' => (int) $r->count,
            ])
            ->values()
            ->all();

        $byMethod = $base()
            ->select('payment_method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($r) => [
                'method' => (string) ($r->payment_method?->value ?? $r->payment_method),
                'total' => (float) $r->total,
                'count' => (int) $r->count,
            ])
            ->values()
            ->all();

        $filterLabel = $params['subcategory_name'] ?? $params['category_name'];
        $notFound = ($params['category_name'] && ! $params['category_found'])
            || ($params['subcategory_name'] && ! $params['subcategory_found']);

        $data = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'branch_name' => $params['branch_name'],
            'total' => $total,
            'count' => $count,
            'top_subcategories' => $topRows,
            'by_payment_method' => $byMethod,
            'category_name' => $params['category_name'],
            'subcategory_name' => $params['subcategory_name'],
            'filter_found' => ! $notFound,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';

        if ($notFound) {
            $summary = sprintf('No encontré "%s" en el catálogo de categorías/subcategorías.', $filterLabel);
        } else {
            $filterText = $filterLabel ? ' en '.$filterLabel : '';
            $summary = sprintf(
                'Gastos%s del %s al %s para %s: $%s en %d movimientos.',
                $filterText,
                $params['date_from'],
                $params['date_to'],
                $branchLabel,
                number_format($total, 2),
                $count,
            );
        }

        return new ToolResult('expense_summary', $data, $summary, $params);
    }

    private function cleanName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 120);
    }
}
