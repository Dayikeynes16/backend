<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\PurchaseStatus;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resumen de compras de un periodo: total comprado, # compras, top proveedores.
 * Para admin-sucursal el branch se fuerza a la suya (defensa idéntica al
 * resto de Read Tools).
 */
class PurchaseSummaryTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_compras';
    }

    public function description(): string
    {
        return 'Devuelve el total comprado a proveedores en un periodo (CMV — no son gastos operativos), número de compras, ticket promedio y top proveedores. Usar para "¿cuánto compré esta semana?", "compras de este mes", "a quién le compré más".';
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
                    'description' => 'Cuántos top proveedores devolver. Default 5.',
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
        $base = fn () => Purchase::query()
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
            ->whereBetween('purchased_at', [$params['date_from'].' 00:00:00', $params['date_to'].' 23:59:59']);

        $totalAmount = (float) $base()->sum('total');
        $count = (int) $base()->count();
        $avg = $count > 0 ? round($totalAmount / $count, 2) : 0.0;
        $pendingTotal = (float) $base()->where('amount_pending', '>', 0)->sum('amount_pending');

        $topProviders = $base()
            ->select('provider_id', DB::raw('SUM(total) as total_amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('provider_id')
            ->orderByDesc('total_amount')
            ->limit($params['top_limit'])
            ->with('provider:id,name')
            ->get()
            ->map(fn ($r) => [
                'provider_id' => (int) $r->provider_id,
                'provider_name' => $r->provider?->name ?? '—',
                'total' => (float) $r->total_amount,
                'count' => (int) $r->count,
            ])
            ->values()
            ->all();

        $data = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'branch_name' => $params['branch_name'],
            'total_amount' => $totalAmount,
            'count' => $count,
            'avg_amount' => $avg,
            'pending_total' => $pendingTotal,
            'top_providers' => $topProviders,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $summary = sprintf(
            'Compras del %s al %s para %s: $%s en %d compras (promedio $%s). Saldo pendiente $%s.',
            $params['date_from'],
            $params['date_to'],
            $branchLabel,
            number_format($totalAmount, 2),
            $count,
            number_format($avg, 2),
            number_format($pendingTotal, 2),
        );

        return new ToolResult('purchase_summary', $data, $summary, $params);
    }
}
