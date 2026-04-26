<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class ProductMetrics extends AbstractMetrics
{
    /**
     * Aplica el filtro de status (completed/pending/cancelled) sobre una
     * query que ya tiene el alias 's' para sales. Si statuses solo contiene
     * 'completed', además exige amount_pending<=0 (la condición histórica).
     */
    private function applyStatusFilter($query, array $statuses): void
    {
        $values = $this->normalizeStatuses($statuses);

        if (empty($values)) {
            $query->whereRaw('1=0'); // Sin statuses → nada

            return;
        }

        $query->whereIn('s.status', $values);

        // Modo histórico (compat): si solo se pidió completed, exigir
        // que la venta esté cobrada al 100%. Si el usuario añadió pending,
        // ya está pidiendo ver entregadas sin cobrar — no aplica el guard.
        if ($values === [SaleStatus::Completed->value]) {
            $query->where('s.amount_pending', '<=', 0);
        }
    }

    private function normalizeStatuses(array $statuses): array
    {
        return collect($statuses)
            ->map(fn ($s) => match (strtolower((string) $s)) {
                'completed' => SaleStatus::Completed->value,
                'pending' => SaleStatus::Pending->value,
                'cancelled' => SaleStatus::Cancelled->value,
                default => null,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Rango de fecha tolerante a pending: completed_at puede ser null
     * (todavía no cobrada), por eso usamos COALESCE con created_at.
     */
    private function dateColumn(): string
    {
        return 'COALESCE(s.completed_at, s.created_at)';
    }

    public function summary(DateRange $range, ?int $branchId, int $tenantId, array $statuses = ['completed']): array
    {
        $base = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween(DB::raw($this->dateColumn()), [$range->start, $range->end]);

        $this->applyStatusFilter($base, $statuses);

        // Totales globales del rango (para los KPIs de cabecera).
        $totals = (clone $base)
            ->selectRaw('
                SUM(si.subtotal) as revenue,
                SUM(COALESCE(si.cost_price_at_sale * si.quantity, 0)) as cost,
                SUM(si.subtotal - COALESCE(si.cost_price_at_sale * si.quantity, 0)) as gross_profit,
                SUM(CASE
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN (\'kg\') THEN si.quantity
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN (\'g\') THEN si.quantity / 1000.0
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN (\'l\') THEN si.quantity
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN (\'ml\') THEN si.quantity / 1000.0
                    ELSE 0
                END) as quantity_kg,
                SUM(CASE
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN (\'piece\', \'cut\', \'unit\') THEN si.quantity
                    ELSE 0
                END) as quantity_units
            ')
            ->first();

        $uniqueCount = (clone $base)->distinct('si.product_id')->count('si.product_id');

        $top = (clone $base)
            ->selectRaw('si.product_id, MAX(si.product_name) as product_name, SUM(si.subtotal) as revenue')
            ->groupBy('si.product_id')
            ->orderByDesc('revenue')
            ->first();

        $mostProfitable = (clone $base)
            ->whereNotNull('si.cost_price_at_sale')
            ->selectRaw('si.product_id, MAX(si.product_name) as product_name, SUM(si.subtotal - si.cost_price_at_sale * si.quantity) as profit')
            ->groupBy('si.product_id')
            ->orderByDesc('profit')
            ->first();

        $noMovementCount = $this->withoutMovement($range, $branchId, $tenantId, 30)->count();

        $revenue = (float) ($totals->revenue ?? 0);
        $profit = (float) ($totals->gross_profit ?? 0);

        return [
            'unique_products_sold' => (int) $uniqueCount,
            'revenue' => round($revenue, 2),
            'cost' => round((float) ($totals->cost ?? 0), 2),
            'gross_profit' => round($profit, 2),
            'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0,
            'quantity_kg' => round((float) ($totals->quantity_kg ?? 0), 3),
            'quantity_units' => (int) round((float) ($totals->quantity_units ?? 0)),
            'top_product' => $top ? [
                'id' => (int) $top->product_id,
                'name' => $top->product_name,
                'revenue' => (float) $top->revenue,
            ] : null,
            'most_profitable_product' => $mostProfitable ? [
                'id' => (int) $mostProfitable->product_id,
                'name' => $mostProfitable->product_name,
                'profit' => (float) $mostProfitable->profit,
            ] : null,
            'no_movement_count' => (int) $noMovementCount,
        ];
    }

    /**
     * Tabla maestra: TODOS los productos vendidos en el rango con sus
     * métricas (revenue, cost, profit, kg, unidades, tickets).
     * Sin LIMIT — el frontend pagina y ordena en cliente.
     */
    public function byProductFull(DateRange $range, ?int $branchId, int $tenantId, array $statuses = ['completed']): array
    {
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween(DB::raw($this->dateColumn()), [$range->start, $range->end]);

        $this->applyStatusFilter($query, $statuses);

        return $query
            ->selectRaw("
                si.product_id,
                MAX(si.product_name) as product_name,
                MAX(c.name) as category_name,
                MAX(p.image_path) as image_path,
                MAX(p.unit_type) as unit_type,
                COUNT(DISTINCT s.id) as ticket_count,
                SUM(CASE
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN ('kg') THEN si.quantity
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN ('g') THEN si.quantity / 1000.0
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN ('l') THEN si.quantity
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN ('ml') THEN si.quantity / 1000.0
                    ELSE 0
                END) as quantity_kg,
                SUM(CASE
                    WHEN COALESCE(si.quantity_unit, si.unit_type) IN ('piece', 'cut', 'unit') THEN si.quantity
                    ELSE 0
                END) as quantity_units,
                SUM(si.subtotal) as revenue,
                SUM(COALESCE(si.cost_price_at_sale * si.quantity, 0)) as cost,
                SUM(si.subtotal - COALESCE(si.cost_price_at_sale * si.quantity, 0)) as gross_profit,
                BOOL_OR(si.cost_price_at_sale IS NULL) as has_missing_cost
            ")
            ->groupBy('si.product_id')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($r) {
                $revenue = (float) $r->revenue;
                $profit = (float) $r->gross_profit;

                return [
                    'product_id' => (int) $r->product_id,
                    'product_name' => $r->product_name,
                    'category_name' => $r->category_name,
                    'image_path' => $r->image_path,
                    'unit_type' => $r->unit_type,
                    'ticket_count' => (int) $r->ticket_count,
                    'quantity_kg' => round((float) $r->quantity_kg, 3),
                    'quantity_units' => (int) round((float) $r->quantity_units),
                    'revenue' => round($revenue, 2),
                    'cost' => round((float) $r->cost, 2),
                    'gross_profit' => round($profit, 2),
                    'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0,
                    'has_missing_cost' => (bool) $r->has_missing_cost,
                ];
            })
            ->all();
    }

    public function topByRevenue(DateRange $range, ?int $branchId, int $tenantId, int $limit = 10, array $statuses = ['completed']): array
    {
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween(DB::raw($this->dateColumn()), [$range->start, $range->end]);

        $this->applyStatusFilter($query, $statuses);

        return $query
            ->selectRaw('si.product_id, MAX(si.product_name) as product_name, SUM(si.quantity) as quantity, SUM(si.subtotal) as revenue, SUM(si.subtotal - COALESCE(si.cost_price_at_sale * si.quantity, 0)) as profit')
            ->groupBy('si.product_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_name' => $r->product_name,
                'quantity' => (float) $r->quantity,
                'revenue' => (float) $r->revenue,
                'profit' => (float) $r->profit,
            ])
            ->all();
    }

    public function topByProfit(DateRange $range, ?int $branchId, int $tenantId, int $limit = 10, array $statuses = ['completed']): array
    {
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereNotNull('si.cost_price_at_sale')
            ->whereBetween(DB::raw($this->dateColumn()), [$range->start, $range->end]);

        $this->applyStatusFilter($query, $statuses);

        return $query
            ->selectRaw('si.product_id, MAX(si.product_name) as product_name, SUM(si.subtotal - si.cost_price_at_sale * si.quantity) as profit, SUM(si.subtotal) as revenue')
            ->groupBy('si.product_id')
            ->orderByDesc('profit')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_name' => $r->product_name,
                'profit' => (float) $r->profit,
                'revenue' => (float) $r->revenue,
            ])
            ->all();
    }

    public function topByQuantity(DateRange $range, ?int $branchId, int $tenantId, int $limit = 10, array $statuses = ['completed']): array
    {
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween(DB::raw($this->dateColumn()), [$range->start, $range->end]);

        $this->applyStatusFilter($query, $statuses);

        return $query
            ->selectRaw('si.product_id, MAX(si.product_name) as product_name, SUM(si.quantity) as quantity, SUM(si.subtotal) as revenue')
            ->groupBy('si.product_id')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_name' => $r->product_name,
                'quantity' => (float) $r->quantity,
                'revenue' => (float) $r->revenue,
            ])
            ->all();
    }

    public function byCategoryShare(DateRange $range, ?int $branchId, int $tenantId, array $statuses = ['completed']): array
    {
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween(DB::raw($this->dateColumn()), [$range->start, $range->end]);

        $this->applyStatusFilter($query, $statuses);

        return $query
            ->selectRaw("COALESCE(c.name, 'Sin categoría') as category, SUM(si.subtotal) as revenue, SUM(si.subtotal - COALESCE(si.cost_price_at_sale * si.quantity, 0)) as profit")
            ->groupBy('c.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'revenue' => (float) $r->revenue,
                'profit' => (float) $r->profit,
            ])
            ->all();
    }

    public function leastSold(DateRange $range, ?int $branchId, int $tenantId, int $limit = 20, array $statuses = ['completed']): array
    {
        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereBetween(DB::raw($this->dateColumn()), [$range->start, $range->end]);

        $this->applyStatusFilter($query, $statuses);

        return $query
            ->selectRaw('si.product_id, MAX(si.product_name) as product_name, SUM(si.quantity) as quantity, SUM(si.subtotal) as revenue')
            ->groupBy('si.product_id')
            ->orderBy('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_name' => $r->product_name,
                'quantity' => (float) $r->quantity,
                'revenue' => (float) $r->revenue,
            ])
            ->all();
    }

    public function withoutMovement(DateRange $range, ?int $branchId, int $tenantId, int $days = 30)
    {
        $cutoff = now()->subDays($days);

        return DB::table('products as p')
            ->leftJoin(DB::raw('(SELECT si.product_id, MAX(s.completed_at) as last_sold
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                WHERE s.status = \''.SaleStatus::Completed->value.'\' AND s.amount_pending <= 0
                GROUP BY si.product_id) lm'), 'lm.product_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('p.branch_id', $branchId))
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('lm.last_sold')->orWhere('lm.last_sold', '<', $cutoff);
            })
            ->select('p.id', 'p.name', 'p.price', 'p.cost_price', 'lm.last_sold');
    }

    public function priceBelowCost(?int $branchId, int $tenantId): array
    {
        return DB::table('products')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('cost_price')
            ->whereColumn('price', '<=', 'cost_price')
            ->select('id', 'name', 'price', 'cost_price')
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'price' => (float) $r->price,
                'cost_price' => (float) $r->cost_price,
            ])
            ->all();
    }
}
