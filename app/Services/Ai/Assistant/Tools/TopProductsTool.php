<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\SaleStatus;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use Illuminate\Support\Facades\DB;

class TopProductsTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_productos_top';
    }

    public function description(): string
    {
        return 'Devuelve los productos más vendidos por ingreso de un periodo. Usar para "¿qué productos se vendieron más este mes?", "top productos de hoy".';
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
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                    'description' => 'Cuántos productos devolver. Default 5.',
                ],
            ],
            'required' => ['scope', 'date_from', 'date_to', 'branch_name', 'limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $scope = (string) ($params['scope'] ?? 'today');
        [$from, $to] = $this->resolveDateRange($scope, $params['date_from'] ?? null, $params['date_to'] ?? null);
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $limit = (int) ($params['limit'] ?? 5);
        $limit = max(1, min(20, $limit));

        return [
            'scope' => $scope,
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'limit' => $limit,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        $tenant = app('tenant');
        $branchId = $params['branch_id'];

        // SaleItem no usa BelongsToTenant (vive bajo Sale), por eso filtramos
        // explícitamente vía join — mismo patrón que Dashboard.
        $rows = SaleItem::query()
            ->select('product_name')
            ->selectRaw('SUM(quantity) as total_qty')
            ->selectRaw('SUM(subtotal) as total_revenue')
            ->whereHas('sale', function ($q) use ($tenant, $branchId, $params) {
                $q->where('tenant_id', $tenant->id)
                    ->when($branchId, fn ($qq) => $qq->where('branch_id', $branchId))
                    ->where('status', SaleStatus::Completed->value)
                    ->whereNull('cancelled_at')
                    ->whereBetween(DB::raw('COALESCE(completed_at, created_at)'), [
                        $params['date_from'].' 00:00:00',
                        $params['date_to'].' 23:59:59',
                    ]);
            })
            ->groupBy('product_name')
            ->orderByDesc('total_revenue')
            ->limit($params['limit'])
            ->get()
            ->map(fn ($r) => [
                'product_name' => (string) $r->product_name,
                'quantity' => (float) $r->total_qty,
                'revenue' => (float) $r->total_revenue,
            ])
            ->values()
            ->all();

        $data = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'branch_name' => $params['branch_name'],
            'limit' => $params['limit'],
            'products' => $rows,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $top = $rows[0]['product_name'] ?? null;

        $summary = $top
            ? sprintf('Top productos para %s del %s al %s. El más vendido fue %s.', $branchLabel, $params['date_from'], $params['date_to'], $top)
            : sprintf('No hubo ventas para %s en ese periodo.', $branchLabel);

        return new ToolResult('top_products', $data, $summary, $params);
    }
}
