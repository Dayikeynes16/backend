<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Expense;
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
        return 'Devuelve el total de gastos, conteo, top de subcategorías y desglose por método de pago en un periodo. Usar para preguntas como "¿cuáles fueron mis gastos más fuertes esta semana?" o "cuánto llevo gastado este mes".';
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
                'top_limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'description' => 'Cuántas subcategorías top devolver. Default 5.',
                ],
            ],
            'required' => ['scope', 'date_from', 'date_to', 'branch_name', 'top_limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $scope = (string) ($params['scope'] ?? 'today');
        [$from, $to] = $this->resolveDateRange($scope, $params['date_from'] ?? null, $params['date_to'] ?? null);
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $limit = (int) ($params['top_limit'] ?? 5);
        $limit = max(1, min(10, $limit));

        return [
            'scope' => $scope,
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'top_limit' => $limit,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        // BelongsToTenant ya filtra por tenant_id automáticamente.
        $base = fn () => Expense::query()
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
            ->whereBetween('expense_at', [$params['date_from'].' 00:00:00', $params['date_to'].' 23:59:59']);

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

        $data = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'branch_name' => $params['branch_name'],
            'total' => $total,
            'count' => $count,
            'top_subcategories' => $topRows,
            'by_payment_method' => $byMethod,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $summary = sprintf(
            'Gastos del %s al %s para %s: $%s en %d movimientos.',
            $params['date_from'],
            $params['date_to'],
            $branchLabel,
            number_format($total, 2),
            $count,
        );

        return new ToolResult('expense_summary', $data, $summary, $params);
    }
}
