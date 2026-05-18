<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\SalesMetrics;
use Carbon\CarbonImmutable;

/**
 * Resume las ventas de un periodo. Reutiliza SalesMetrics para que las cifras
 * coincidan exactamente con las que muestra el módulo de Métricas.
 *
 * Para admin-sucursal `branch_name` se ignora y se fuerza a su sucursal.
 */
class SalesSummaryTool extends AbstractAssistantTool
{
    public function __construct(private readonly SalesMetrics $sales) {}

    public function name(): string
    {
        return 'consultar_ventas';
    }

    public function description(): string
    {
        return 'Devuelve el total de ventas netas, número de tickets, ticket promedio y cancelaciones de un periodo. Usar para preguntas como "¿cuánto vendí hoy?", "ventas de esta semana", "cuánto vendió la sucursal Centro ayer".';
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
                    'description' => 'Periodo a consultar. Si el usuario dice "hoy" usa today; "esta semana" this_week, etc. Sólo usa custom si el usuario da fechas explícitas.',
                ],
                'date_from' => [
                    'type' => ['string', 'null'],
                    'description' => 'Fecha inicio YYYY-MM-DD. Sólo cuando scope=custom.',
                ],
                'date_to' => [
                    'type' => ['string', 'null'],
                    'description' => 'Fecha fin YYYY-MM-DD. Sólo cuando scope=custom.',
                ],
                'branch_name' => [
                    'type' => ['string', 'null'],
                    'description' => 'Nombre de la sucursal. Si el admin-empresa no la indica, se devuelve la suma de todas las sucursales del tenant.',
                ],
            ],
            'required' => ['scope', 'date_from', 'date_to', 'branch_name'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $scope = (string) ($params['scope'] ?? 'today');
        [$from, $to] = $this->resolveDateRange($scope, $params['date_from'] ?? null, $params['date_to'] ?? null);
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);

        return [
            'scope' => $scope,
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        $tenant = app('tenant');
        $range = DateRange::custom($params['date_from'], $params['date_to']);

        $summary = $this->sales->summary($range, $params['branch_id'], $tenant->id, ['completed']);
        $current = $summary['current'];
        $previous = $summary['previous'];

        $net = (float) ($current['net_sales'] ?? 0);
        $prevNet = (float) ($previous['net_sales'] ?? 0);
        $deltaPct = $prevNet > 0 ? round((($net - $prevNet) / $prevNet) * 100, 1) : null;

        $data = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'branch_name' => $params['branch_name'],
            'net_sales' => $net,
            'gross_sales' => (float) ($current['gross_sales'] ?? 0),
            'ticket_count' => (int) ($current['ticket_count'] ?? 0),
            'avg_ticket' => (float) ($current['avg_ticket'] ?? 0),
            'cancelled_amount' => (float) ($current['cancelled_amount'] ?? 0),
            'cancelled_count' => (int) ($current['cancelled_count'] ?? 0),
            'previous_net_sales' => $prevNet,
            'delta_pct' => $deltaPct,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $periodLabel = $this->periodLabel($params['scope'], $params['date_from'], $params['date_to']);

        $summaryText = sprintf(
            'Ventas netas %s para %s: $%s en %d tickets (ticket promedio $%s).',
            $periodLabel,
            $branchLabel,
            number_format($net, 2),
            $data['ticket_count'],
            number_format($data['avg_ticket'], 2),
        );

        return new ToolResult('sales_summary', $data, $summaryText, $params);
    }

    private function periodLabel(string $scope, string $from, string $to): string
    {
        $labels = [
            'today' => 'de hoy',
            'yesterday' => 'de ayer',
            'this_week' => 'de esta semana',
            'last_week' => 'de la semana pasada',
            'this_month' => 'de este mes',
            'last_month' => 'del mes pasado',
        ];

        if (isset($labels[$scope])) {
            return $labels[$scope];
        }

        $tz = config('app.timezone');

        return $from === $to
            ? 'del '.CarbonImmutable::parse($from, $tz)->isoFormat('D [de] MMMM')
            : 'del '.CarbonImmutable::parse($from, $tz)->isoFormat('D MMM').' al '.CarbonImmutable::parse($to, $tz)->isoFormat('D MMM');
    }
}
