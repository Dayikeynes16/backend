<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class ProductMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $base = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end]);

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

        return [
            'unique_products_sold' => (int) $uniqueCount,
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

    public function topByRevenue(DateRange $range, ?int $branchId, int $tenantId, int $limit = 10): array
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->selectRaw('si.product_id, MAX(si.product_name) as product_name, SUM(si.quantity) as quantity, SUM(si.subtotal) as revenue')
            ->groupBy('si.product_id')
            ->orderByDesc('revenue')
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

    public function topByQuantity(DateRange $range, ?int $branchId, int $tenantId, int $limit = 10): array
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
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

    public function byCategoryShare(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
            ->selectRaw("COALESCE(c.name, 'Sin categoría') as category, SUM(si.subtotal) as revenue")
            ->groupBy('c.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'revenue' => (float) $r->revenue,
            ])
            ->all();
    }

    public function leastSold(DateRange $range, ?int $branchId, int $tenantId, int $limit = 20): array
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->where('s.status', SaleStatus::Completed->value)
            ->where('s.amount_pending', '<=', 0)
            ->whereBetween('s.completed_at', [$range->start, $range->end])
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
