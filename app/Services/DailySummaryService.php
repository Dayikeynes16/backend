<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Services\Metrics\DateRange;
use App\Services\Metrics\SalesMetrics;
use Illuminate\Support\Facades\DB;

/**
 * Resumen "del día" para las pantallas operativas: Dashboard (Sucursal y
 * Empresa), Historial y Pagos.
 *
 * Los agregados de venta (brutas, netas, # tickets, ticket promedio,
 * cancelaciones) se delegan a {@see SalesMetrics} para que estas pantallas
 * usen exactamente el mismo glosario y la misma fecha canónica
 * — COALESCE(completed_at, created_at) — que el módulo de Métricas. Así, para
 * un mismo día, Dashboard / Historial / Métricas muestran los mismos números.
 *
 * Lo único propio de este servicio es la cobranza del día desglosada por
 * método y por antigüedad de la venta (abonos a cuentas anteriores): un
 * cálculo específico de "hoy" que no aplica a rangos arbitrarios y por eso no
 * vive en SalesMetrics.
 *
 * @see docs/modulos/metricas.md — Glosario canónico
 *
 * @phpstan-type SalesSummary array{gross_sales: float, net_sales: float, ticket_count: int, avg_ticket: float, cancelled_amount: float, cancelled_count: int}
 * @phpstan-type MethodCollections array{method: string, label: string, total: float, count: int, from_today: float, from_previous: float}
 * @phpstan-type CollectionsSummary array{total: float, from_today: float, from_previous: float, payment_count: int, by_method: list<MethodCollections>}
 * @phpstan-type DaySummary array{sales: SalesSummary, sales_yesterday: SalesSummary, delta_pct: float|null, collections: CollectionsSummary}
 */
final class DailySummaryService
{
    public function __construct(private readonly SalesMetrics $sales) {}

    /**
     * Resumen completo de un día para una sucursal (o todo el tenant).
     *
     * @param  int|null  $branchId  null = agrega todas las sucursales del tenant
     * @param  list<string>  $paymentMethods  métodos habilitados (define el orden de presentación)
     * @param  int|null  $userId  null = todos los cajeros; si se pasa, filtra
     *                            la cobranza (collections) por ese usuario. NO
     *                            afecta los agregados de venta — ventas no se
     *                            atribuyen a un solo cajero.
     * @param  list<string>  $statuses  estados de venta a incluir en los agregados
     *                                  (net_sales, ticket_count, delta, etc.).
     *                                  Default `['completed']`. Pasar `['completed','pending']`
     *                                  para incluir pendientes desde el dashboard.
     *                                  NO afecta `collections` (los pagos cuentan
     *                                  independientemente del estado de la venta).
     * @return DaySummary
     */
    public function forDate(
        ?int $branchId,
        int $tenantId,
        string $date,
        array $paymentMethods = ['cash', 'card', 'transfer'],
        ?int $userId = null,
        array $statuses = ['completed'],
    ): array {
        $range = DateRange::custom($date, $date);
        $summary = $this->sales->summary($range, $branchId, $tenantId, $statuses);

        $current = $this->normalizeSales($summary['current']);
        $previous = $this->normalizeSales($summary['previous']);

        return [
            'sales' => $current,
            'sales_yesterday' => $previous,
            'delta_pct' => $this->deltaPct($current['net_sales'], $previous['net_sales']),
            'collections' => $this->collections($range, $branchId, $tenantId, $paymentMethods, $userId),
        ];
    }

    /**
     * Ventas por hora del día (0–23) para el chart "ventas por hora" del
     * dashboard. Pass-through tipado sobre {@see SalesMetrics::hourlySeries()}.
     *
     * @param  int|null  $branchId  null = todas las sucursales del tenant
     * @param  list<string>  $statuses  estados a incluir (default solo completadas)
     * @return array<int, array{trx: int, total: float}>
     */
    public function hourlySeries(?int $branchId, int $tenantId, string $date, array $statuses = ['completed']): array
    {
        return $this->sales->hourlySeries(DateRange::custom($date, $date), $branchId, $tenantId, $statuses);
    }

    /**
     * Mapea el bloque 'current'/'previous' de {@see SalesMetrics::summary()} a
     * la forma estable que consumen los controllers.
     *
     * @param  array<string, mixed>  $row
     * @return SalesSummary
     */
    private function normalizeSales(array $row): array
    {
        $ticketCount = (int) ($row['ticket_count'] ?? 0);
        $netSales = round((float) ($row['net_sales'] ?? 0), 2);

        return [
            'gross_sales' => round((float) ($row['gross_sales'] ?? 0), 2),
            'net_sales' => $netSales,
            'ticket_count' => $ticketCount,
            'avg_ticket' => $ticketCount > 0 ? round($netSales / $ticketCount, 2) : 0.0,
            'cancelled_amount' => round((float) ($row['cancelled_amount'] ?? 0), 2),
            'cancelled_count' => (int) ($row['cancelled_count'] ?? 0),
        ];
    }

    private function deltaPct(float $current, float $previous): ?float
    {
        if ($previous === 0.0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Cobranza del día: pagos cuyo `created_at` cae en la fecha, agrupados por
     * método. Cada método incluye el split por antigüedad de la venta a la que
     * se aplica: `from_today` (ventas cuyo día canónico es la fecha) vs
     * `from_previous` (abonos a ventas de días anteriores). Se listan todos los
     * métodos habilitados — incluso con $0 — para que el UI los muestre.
     * Excluye pagos soft-deleted (los que arrastran ventas canceladas).
     *
     * Si se pasa $userId, filtra la cobranza al cajero indicado (p.user_id).
     *
     * @param  list<string>  $paymentMethods
     * @return CollectionsSummary
     */
    private function collections(DateRange $range, ?int $branchId, int $tenantId, array $paymentMethods, ?int $userId = null): array
    {
        $rows = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->when($userId, fn ($q) => $q->where('p.user_id', $userId))
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->whereBetween('p.created_at', [$range->start, $range->end])
            ->selectRaw('
                p.method as method,
                COALESCE(SUM(p.amount), 0) as total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(s.completed_at, s.created_at)) >= DATE(p.created_at) THEN p.amount END), 0) as from_today,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(s.completed_at, s.created_at)) <  DATE(p.created_at) THEN p.amount END), 0) as from_previous,
                COUNT(*) as count
            ')
            ->groupBy('p.method')
            ->get()
            ->keyBy('method');

        // Métodos habilitados primero (en orden), luego cualquier método extra que haya aparecido.
        $orderedMethods = collect($paymentMethods)
            ->merge($rows->keys()->diff($paymentMethods))
            ->unique()
            ->values();

        $byMethod = [];
        $total = 0.0;
        $fromToday = 0.0;
        $fromPrevious = 0.0;
        $paymentCount = 0;

        foreach ($orderedMethods as $method) {
            $row = $rows->get($method);
            $amount = (float) ($row->total ?? 0);
            $rowFromToday = (float) ($row->from_today ?? 0);
            $rowFromPrevious = (float) ($row->from_previous ?? 0);
            $rowCount = (int) ($row->count ?? 0);

            $byMethod[] = [
                'method' => (string) $method,
                'label' => PaymentMethod::resolveLabel((string) $method),
                'total' => round($amount, 2),
                'count' => $rowCount,
                'from_today' => round($rowFromToday, 2),
                'from_previous' => round($rowFromPrevious, 2),
            ];

            $total += $amount;
            $fromToday += $rowFromToday;
            $fromPrevious += $rowFromPrevious;
            $paymentCount += $rowCount;
        }

        return [
            'total' => round($total, 2),
            'from_today' => round($fromToday, 2),
            'from_previous' => round($fromPrevious, 2),
            'payment_count' => $paymentCount,
            'by_method' => $byMethod,
        ];
    }
}
