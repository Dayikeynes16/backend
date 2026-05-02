<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use App\Support\SaleItemMath;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de margen y rentabilidad.
 *
 * Regla de cálculo: todos los SUM/AVG de margen se calculan sólo sobre
 * items con `sale_items.cost_price_at_sale IS NOT NULL`. Los conteos
 * de cobertura (items con costo vs. sin costo) se calculan en queries
 * separados sin ese filtro, para poder reportar el universo excluido.
 *
 * Semántica del campo `revenue`: representa el ingreso de items con
 * costo registrado (denominador correcto del `margin_pct`). El ingreso
 * total de la sucursal en el rango (incluyendo items sin costo) vive en
 * `SalesMetrics::summary()`. No se mezclan en el mismo payload.
 */
class MarginMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return [
            'current' => $this->aggregateFor($range, $branchId, $tenantId),
            'previous' => $this->aggregateFor($range->previousComparable(), $branchId, $tenantId),
        ];
    }

    public function aggregateFor(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $lineCost = SaleItemMath::lineCostSql('si');

        $row = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->whereNotNull('si.cost_price_at_sale')
            ->selectRaw('
                COALESCE(SUM(si.subtotal), 0) as revenue,
                COALESCE(SUM('.$lineCost.'), 0) as cost,
                COALESCE(SUM(si.subtotal - ('.$lineCost.')), 0) as gross_profit
            ')
            ->first();

        $coverage = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN si.cost_price_at_sale IS NULL THEN 1 ELSE 0 END), 0) as items_without_cost,
                COALESCE(SUM(CASE WHEN si.cost_price_at_sale IS NOT NULL THEN 1 ELSE 0 END), 0) as items_with_cost
            ')
            ->first();

        $revenue = (float) $row->revenue;
        $gross = (float) $row->gross_profit;
        $marginPct = $revenue > 0 ? round(($gross / $revenue) * 100, 2) : 0.0;

        $tickets = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Completed->value)
            ->where('amount_pending', '<=', 0)
            ->whereBetween('completed_at', [$range->start, $range->end])
            ->count();

        return [
            'revenue' => $revenue,
            'cost' => (float) $row->cost,
            'gross_profit' => $gross,
            'margin_pct' => $marginPct,
            'avg_margin_per_ticket' => $tickets > 0 ? round($gross / $tickets, 2) : 0.0,
            'items_without_cost' => (int) $coverage->items_without_cost,
            'items_with_cost' => (int) $coverage->items_with_cost,
        ];
    }

    public function dailyGrossProfit(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $lineCost = SaleItemMath::lineCostSql('si');

        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereNull('s.deleted_at')
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->whereNotNull('si.cost_price_at_sale')
            ->selectRaw('DATE(s.completed_at) as day, SUM(si.subtotal - ('.$lineCost.')) as gross_profit')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'gross_profit' => (float) $r->gross_profit,
            ])
            ->all();
    }

    public function byCategory(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $lineCost = SaleItemMath::lineCostSql('si');

        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->whereNotNull('si.cost_price_at_sale')
            ->selectRaw("
                COALESCE(c.name, 'Sin categoría') as category,
                SUM(si.subtotal) as revenue,
                SUM(".$lineCost.') as cost,
                SUM(si.subtotal - ('.$lineCost.')) as gross_profit
            ')
            ->groupBy('c.name')
            ->orderByDesc('gross_profit')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'revenue' => (float) $r->revenue,
                'cost' => (float) $r->cost,
                'gross_profit' => (float) $r->gross_profit,
                'margin_pct' => (float) $r->revenue > 0 ? round(((float) $r->gross_profit / (float) $r->revenue) * 100, 2) : 0.0,
            ])
            ->all();
    }

    public function byProduct(DateRange $range, ?int $branchId, int $tenantId, int $limit = 100): array
    {
        $lineCost = SaleItemMath::lineCostSql('si');

        $rows = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->whereNotNull('si.cost_price_at_sale')
            ->selectRaw('
                si.product_id,
                MAX(si.product_name) as product_name,
                SUM(si.quantity) as quantity,
                SUM(si.subtotal) as revenue,
                SUM('.$lineCost.') as cost,
                SUM(si.subtotal - ('.$lineCost.')) as gross_profit
            ')
            ->groupBy('si.product_id')
            ->orderByDesc('gross_profit')
            ->limit($limit)
            ->get();

        $productIdsWithMissingCost = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->whereNull('si.cost_price_at_sale')
            ->distinct()
            ->pluck('si.product_id')
            ->all();

        $missingSet = array_flip($productIdsWithMissingCost);

        return $rows->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'product_name' => $r->product_name,
            'quantity' => (float) $r->quantity,
            'revenue' => (float) $r->revenue,
            'cost' => (float) $r->cost,
            'gross_profit' => (float) $r->gross_profit,
            'margin_pct' => (float) $r->revenue > 0 ? round(((float) $r->gross_profit / (float) $r->revenue) * 100, 2) : 0.0,
            'has_missing_cost' => isset($missingSet[(int) $r->product_id]),
        ])->all();
    }
}
