<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;

/**
 * Agregador de la pantalla «Resumen». No reimplementa cálculos: compone
 * SalesMetrics (ventas), MarginMetrics (CMV / utilidad bruta / cobertura) y
 * CollectionMetrics (cobranza), y añade gastos, compras (caja), comparación
 * vs período anterior, comparación por sucursal y alertas derivadas de datos.
 */
class OverviewMetrics extends AbstractMetrics
{
    /** Umbral de margen por producto para alertar (porcentaje). */
    private const LOW_MARGIN_PCT = 15.0;

    /** Alza de gastos vs período anterior que dispara alerta. */
    private const EXPENSE_SPIKE_RATIO = 0.25;

    /** Caída de ventas de una sucursal vs período anterior que alerta. */
    private const BRANCH_DROP_RATIO = -0.15;

    public function __construct(
        private readonly SalesMetrics $sales,
        private readonly MarginMetrics $margin,
        private readonly CollectionMetrics $collection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(DateRange $range, ?int $branchId, int $tenantId, bool $withBranchComparison): array
    {
        $prev = $range->previousComparable();

        $marginNow = $this->margin->aggregateFor($range, $branchId, $tenantId);
        $marginPrev = $this->margin->aggregateFor($prev, $branchId, $tenantId);

        $salesNow = (float) $this->sales->summary($range, $branchId, $tenantId)['current']['net_sales'];
        $salesPrev = (float) $this->sales->summary($prev, $branchId, $tenantId)['current']['net_sales'];

        $gastosNow = $this->expensesTotal($range, $branchId, $tenantId);
        $gastosPrev = $this->expensesTotal($prev, $branchId, $tenantId);

        $grossNow = (float) $marginNow['gross_profit'];
        $grossPrev = (float) $marginPrev['gross_profit'];
        $utilidadNow = round($grossNow - $gastosNow, 2);
        $utilidadPrev = round($grossPrev - $gastosPrev, 2);

        $itemsWith = (int) $marginNow['items_with_cost'];
        $itemsWithout = (int) $marginNow['items_without_cost'];
        $totalItems = $itemsWith + $itemsWithout;
        $coveragePct = $totalItems > 0 ? round(($itemsWith / $totalItems) * 100, 1) : 100.0;

        return [
            'pnl' => [
                'ventas_netas' => $salesNow,
                'revenue_covered' => (float) $marginNow['revenue'],
                'cmv' => (float) $marginNow['cost'],
                'utilidad_bruta' => $grossNow,
                'margin_pct' => (float) $marginNow['margin_pct'],
                'gastos' => $gastosNow,
                'utilidad_neta' => $utilidadNow,
                'coverage' => [
                    'items_with_cost' => $itemsWith,
                    'items_without_cost' => $itemsWithout,
                    'pct' => $coveragePct,
                ],
            ],
            'compras' => $this->purchasesTotal($range, $branchId, $tenantId),
            'kpis' => [
                'utilidad_neta' => $this->deltaPct($utilidadNow, $utilidadPrev),
                'ventas' => $this->deltaPct($salesNow, $salesPrev),
                'margen' => $this->deltaPts((float) $marginNow['margin_pct'], (float) $marginPrev['margin_pct']),
                'gastos' => $this->deltaPct($gastosNow, $gastosPrev),
            ],
            'top_products' => $this->margin->byProduct($range, $branchId, $tenantId, 5),
            'branch_comparison' => $withBranchComparison ? $this->branchComparison($range, $tenantId) : null,
            'alerts' => $this->alerts($range, $prev, $branchId, $tenantId, $gastosNow, $gastosPrev, $withBranchComparison),
        ];
    }

    private function expensesTotal(DateRange $range, ?int $branchId, int $tenantId): float
    {
        return (float) DB::table('expenses')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('cancelled_by')
            ->whereNull('deleted_at')
            ->whereBetween('expense_at', [$range->start, $range->end])
            ->sum('amount');
    }

    private function purchasesTotal(DateRange $range, ?int $branchId, int $tenantId): float
    {
        return (float) DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', 'received')
            ->whereNull('deleted_at')
            ->whereBetween('purchased_at', [$range->start, $range->end])
            ->sum('total');
    }

    /**
     * Ventas netas por sucursal (solo empresa, vista "todas").
     *
     * @return list<array{branch_id: int, name: string, net_sales: float}>
     */
    private function branchComparison(DateRange $range, int $tenantId): array
    {
        return DB::table('branches')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => [
                'branch_id' => (int) $b->id,
                'name' => $b->name,
                'net_sales' => (float) $this->sales->summary($range, (int) $b->id, $tenantId)['current']['net_sales'],
            ])
            ->sortByDesc('net_sales')
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, severity: string, title: string, detail: string}>
     */
    private function alerts(DateRange $range, DateRange $prev, ?int $branchId, int $tenantId, float $gastosNow, float $gastosPrev, bool $isEmpresa): array
    {
        $alerts = [];

        // 1) Cobranza vencida (> 30 días).
        $aging = $this->collection->aging($branchId, $tenantId);
        $overdue = (float) $aging['31-60'] + (float) $aging['61-plus'];
        if ($overdue > 0) {
            $clients = collect($this->collection->receivablesTable($branchId, $tenantId))
                ->filter(fn ($r) => $r['age_days'] > 30)
                ->count();
            $alerts[] = [
                'type' => 'cobranza_vencida',
                'severity' => 'red',
                'title' => 'Cobranza vencida: '.$this->money($overdue),
                'detail' => $clients.' '.($clients === 1 ? 'cliente con saldo a más de 30 días' : 'clientes con saldo a más de 30 días'),
            ];
        }

        // 2) Productos con margen bajo.
        foreach ($this->margin->byProduct($range, $branchId, $tenantId, 100) as $p) {
            if ($p['revenue'] > 0 && $p['margin_pct'] < self::LOW_MARGIN_PCT) {
                $alerts[] = [
                    'type' => 'margen_bajo',
                    'severity' => 'amber',
                    'title' => 'Margen bajo: "'.$p['product_name'].'" al '.rtrim(rtrim(number_format($p['margin_pct'], 1), '0'), '.').'%',
                    'detail' => 'Vendido '.$this->money($p['revenue']).' en el período',
                ];
                if (count(array_filter($alerts, fn ($a) => $a['type'] === 'margen_bajo')) >= 2) {
                    break;
                }
            }
        }

        // 3) Gastos al alza vs período anterior.
        if ($gastosPrev > 0 && ($gastosNow - $gastosPrev) / $gastosPrev >= self::EXPENSE_SPIKE_RATIO) {
            $pct = round((($gastosNow - $gastosPrev) / $gastosPrev) * 100);
            $alerts[] = [
                'type' => 'gasto_alza',
                'severity' => 'amber',
                'title' => 'Gastos +'.$pct.'% vs período anterior',
                'detail' => $this->money($gastosNow).' vs '.$this->money($gastosPrev),
            ];
        }

        // 4) Sucursales a la baja (solo empresa, vista "todas").
        if ($isEmpresa) {
            foreach (DB::table('branches')->where('tenant_id', $tenantId)->get(['id', 'name']) as $b) {
                $cur = (float) $this->sales->summary($range, (int) $b->id, $tenantId)['current']['net_sales'];
                $old = (float) $this->sales->summary($prev, (int) $b->id, $tenantId)['current']['net_sales'];
                if ($old > 0 && ($cur - $old) / $old <= self::BRANCH_DROP_RATIO) {
                    $pct = round((($cur - $old) / $old) * 100);
                    $alerts[] = [
                        'type' => 'sucursal_baja',
                        'severity' => 'blue',
                        'title' => $b->name.' '.$pct.'% vs período anterior',
                        'detail' => $this->money($cur).' vs '.$this->money($old),
                    ];
                }
            }
        }

        return array_slice($alerts, 0, 6);
    }

    /**
     * @return array{value: float, previous: float, delta_pct: ?float}
     */
    private function deltaPct(float $current, float $previous): array
    {
        return [
            'value' => $current,
            'previous' => $previous,
            'delta_pct' => $previous != 0.0 ? round((($current - $previous) / abs($previous)) * 100, 1) : null,
        ];
    }

    /**
     * @return array{value: float, previous: float, delta_pts: float}
     */
    private function deltaPts(float $current, float $previous): array
    {
        return [
            'value' => $current,
            'previous' => $previous,
            'delta_pts' => round($current - $previous, 1),
        ];
    }

    private function money(float $v): string
    {
        return '$'.number_format($v, 2);
    }
}
