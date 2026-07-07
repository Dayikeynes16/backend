<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\SalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

        $current = $this->sales->summary($range, $params['branch_id'], $tenant->id, ['completed'])['current'];
        $previous = $this->sales
            ->summary($range->previousComparable(), $params['branch_id'], $tenant->id, ['completed'])['current'];

        $net = (float) ($current['net_sales'] ?? 0);
        $prevNet = (float) ($previous['net_sales'] ?? 0);
        $deltaPct = $prevNet > 0 ? round((($net - $prevNet) / $prevNet) * 100, 1) : null;

        // Cobranza del periodo (pagos RECIBIDOS en el rango), misma semántica
        // que el dashboard (DailySummaryService::collections): un pago cuya
        // venta se CREÓ un día anterior al del pago es un abono a una venta de
        // días anteriores. Sin este dato el modelo inventaba la respuesta.
        $collections = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.tenant_id', $tenant->id)
            ->when($params['branch_id'], fn ($q) => $q->where('s.branch_id', $params['branch_id']))
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->whereBetween('p.created_at', [$range->start, $range->end])
            ->selectRaw('
                COALESCE(SUM(p.amount), 0) as total,
                COALESCE(SUM(CASE WHEN DATE(s.created_at) >= DATE(p.created_at) THEN p.amount END), 0) as from_same_day,
                COALESCE(SUM(CASE WHEN DATE(s.created_at) <  DATE(p.created_at) THEN p.amount END), 0) as from_previous
            ')
            ->first();

        $collectedTotal = round((float) ($collections->total ?? 0), 2);
        $collectedFromPrevious = round((float) ($collections->from_previous ?? 0), 2);

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
            'collected_total' => $collectedTotal,
            'collected_from_same_day' => round((float) ($collections->from_same_day ?? 0), 2),
            'collected_from_previous_days' => $collectedFromPrevious,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $periodLabel = $this->periodLabel($params['scope'], $params['date_from'], $params['date_to']);

        $summaryText = sprintf(
            'Ventas netas %s para %s: $%s en %d tickets (ticket promedio $%s). '
            .'Cobranza del periodo (pagos recibidos): $%s, de los cuales $%s son abonos a ventas creadas en días anteriores. '
            .'NOTA de semántica: las ventas netas se miden por fecha de cierre de la venta — una venta a crédito de días '
            .'anteriores que se terminó de cobrar en este periodo SÍ cuenta en las ventas netas del periodo. Si el usuario '
            .'pregunta por el desglose ventas-de-hoy vs abonos, usa estas cifras de cobranza; no afirmes nada que no esté aquí.',
            $periodLabel,
            $branchLabel,
            number_format($net, 2),
            $data['ticket_count'],
            number_format($data['avg_ticket'], 2),
            number_format($collectedTotal, 2),
            number_format($collectedFromPrevious, 2),
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
