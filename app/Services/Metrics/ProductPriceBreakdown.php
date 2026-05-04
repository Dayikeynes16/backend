<?php

namespace App\Services\Metrics;

use App\Enums\SaleStatus;
use App\Support\SaleItemMath;
use Illuminate\Support\Facades\DB;

/**
 * Desglose de un producto en un rango: agrupa las líneas de venta por
 * precio aplicado, por cliente y por tipo de venta (peso/presentación/pieza)
 * para responder "¿a qué precios vendí?, ¿quién compra más barato?, ¿cómo
 * se compone realmente la venta?".
 *
 * Detecta el origen del precio:
 *   - catalog       → unit_price === original_unit_price
 *   - markup        → unit_price >  original_unit_price (raro)
 *   - preferential  → unit_price <  original_unit_price y el cliente tiene
 *                     un customer_product_prices para ese producto
 *   - discounted    → unit_price <  original_unit_price sin match preferencial
 */
class ProductPriceBreakdown
{
    /**
     * @param  list<string>  $statuses
     * @return array{
     *     product: array{id:int,name:string,catalog_price:float|null,catalog_cost:float|null},
     *     range: array{from:string,to:string},
     *     by_price: list<array<string,mixed>>,
     *     by_customer: list<array<string,mixed>>,
     *     by_sale_type: list<array<string,mixed>>,
     *     summary: array<string,mixed>
     * }
     */
    public function build(int $productId, int $tenantId, ?int $branchId, DateRange $range, array $statuses): array
    {
        $statusValues = $this->normalizeStatuses($statuses);

        $base = function () use ($productId, $tenantId, $branchId, $range, $statusValues) {
            $q = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('si.product_id', $productId)
                ->where('s.tenant_id', $tenantId)
                ->whereNull('s.deleted_at')
                ->whereBetween(DB::raw('COALESCE(s.completed_at, s.created_at)'), [$range->start, $range->end]);

            if ($branchId !== null) {
                $q->where('s.branch_id', $branchId);
            }

            if (empty($statusValues)) {
                $q->whereRaw('1=0');
            } else {
                $q->whereIn('s.status', $statusValues);
                if (! in_array(SaleStatus::Cancelled->value, $statusValues, true)) {
                    $q->whereNull('s.cancelled_at');
                }
            }

            return $q;
        };

        $lineCost = SaleItemMath::lineCostSql('si');

        // Producto + catalog price/cost para contexto.
        $product = DB::table('products')
            ->where('id', $productId)
            ->first(['id', 'name', 'price', 'cost_price']);

        // Clasificación per-line. Necesita LEFT JOIN a customer_product_prices
        // para detectar si el descuento viene de un cliente preferencial.
        $discountKindCase = "
            CASE
                WHEN si.unit_price = si.original_unit_price THEN 'catalog'
                WHEN si.unit_price > si.original_unit_price THEN 'markup'
                WHEN cpp.id IS NOT NULL THEN 'preferential'
                ELSE 'discounted'
            END
        ";

        // === BY PRICE ===========================================================
        $byPrice = $base()
            ->leftJoin('customer_product_prices as cpp', function ($join) {
                $join->on('cpp.customer_id', '=', 's.customer_id')
                    ->on('cpp.product_id', '=', 'si.product_id');
            })
            ->selectRaw("
                si.unit_price,
                si.original_unit_price,
                si.presentation_id,
                si.presentation_snapshot->>'name' as presentation_name,
                si.presentation_snapshot->>'unit' as presentation_unit,
                (si.presentation_snapshot->>'content')::numeric as presentation_content,
                si.quantity_unit,
                si.sale_mode_at_sale,
                {$discountKindCase} as discount_kind,
                COUNT(*) as lines,
                SUM(si.quantity) as volume,
                SUM(si.subtotal) as revenue,
                SUM({$lineCost}) as cost,
                COUNT(DISTINCT s.id) as ticket_count,
                COUNT(DISTINCT s.customer_id) FILTER (WHERE s.customer_id IS NOT NULL) as distinct_customers
            ")
            ->groupBy(
                'si.unit_price',
                'si.original_unit_price',
                'si.presentation_id',
                DB::raw("si.presentation_snapshot->>'name'"),
                DB::raw("si.presentation_snapshot->>'unit'"),
                DB::raw("(si.presentation_snapshot->>'content')::numeric"),
                'si.quantity_unit',
                'si.sale_mode_at_sale',
                DB::raw($discountKindCase)
            )
            ->orderByDesc('revenue')
            ->get();

        $tiers = $byPrice->map(function ($r) {
            $unit = $r->presentation_unit;
            $content = $r->presentation_content !== null ? (float) $r->presentation_content : null;
            $isWeight = in_array($unit, ['kg', 'l', 'g', 'ml'], true)
                || in_array($r->quantity_unit, ['kg', 'l', 'g', 'ml'], true);
            $kgEquivalent = $this->volumeInBaseUnit((float) $r->volume, $unit, $content, $r->quantity_unit);

            $label = $r->presentation_name
                ?? ($isWeight ? 'Por kilo' : 'Por pieza');

            $revenue = (float) $r->revenue;
            $cost = (float) $r->cost;
            $profit = $revenue - $cost;

            return [
                'unit_price' => (float) $r->unit_price,
                'original_unit_price' => $r->original_unit_price !== null ? (float) $r->original_unit_price : null,
                'label' => $label,
                'presentation_unit' => $unit,
                'presentation_content' => $content,
                'quantity_unit' => $r->quantity_unit,
                'sale_mode' => $r->sale_mode_at_sale,
                'is_weight' => $isWeight,
                'discount_kind' => $r->discount_kind,
                'lines' => (int) $r->lines,
                'volume' => (float) $r->volume,
                'kg_equivalent' => $kgEquivalent,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => round($profit, 2),
                'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
                'ticket_count' => (int) $r->ticket_count,
                'distinct_customers' => (int) $r->distinct_customers,
            ];
        })->all();

        // Top customer SOLO para tiers preferenciales — único caso donde
        // saber "quién compra más barato" tiene valor inmediato.
        $tiers = $this->attachTopCustomerToPreferentialTiers(
            $tiers, $productId, $tenantId, $branchId, $range, $statusValues
        );

        // === BY CUSTOMER ========================================================
        $byCustomer = $base()
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->selectRaw("
                s.customer_id,
                MAX(c.name) as customer_name,
                COUNT(*) as lines,
                COUNT(DISTINCT s.id) as ticket_count,
                SUM(si.quantity) as volume,
                SUM(si.subtotal) as revenue,
                SUM({$lineCost}) as cost,
                AVG(si.unit_price) as avg_unit_price,
                MIN(si.unit_price) as lowest_unit_price,
                MAX(si.unit_price) as highest_unit_price
            ")
            ->groupBy('s.customer_id')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                $revenue = (float) $r->revenue;
                $cost = (float) $r->cost;
                $profit = $revenue - $cost;

                return [
                    'customer_id' => $r->customer_id,
                    'customer_name' => $r->customer_id ? $r->customer_name : 'Sin cliente',
                    'lines' => (int) $r->lines,
                    'ticket_count' => (int) $r->ticket_count,
                    'volume' => (float) $r->volume,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => round($profit, 2),
                    'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
                    'avg_unit_price' => round((float) $r->avg_unit_price, 2),
                    'lowest_unit_price' => (float) $r->lowest_unit_price,
                    'highest_unit_price' => (float) $r->highest_unit_price,
                ];
            })->all();

        // === BY SALE TYPE ======================================================
        // Normaliza sale_mode_at_sale (legacy puede ser null) usando quantity_unit.
        $bySaleType = $base()
            ->selectRaw("
                COALESCE(
                    si.sale_mode_at_sale,
                    CASE WHEN si.quantity_unit IN ('kg','l','g','ml') THEN 'weight' ELSE 'piece' END
                ) as mode,
                COUNT(*) as lines,
                SUM(si.quantity) as volume,
                SUM(si.subtotal) as revenue,
                SUM({$lineCost}) as cost,
                COUNT(DISTINCT s.id) as ticket_count,
                AVG(si.unit_price) as avg_unit_price
            ")
            ->groupBy('mode')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($r) {
                $revenue = (float) $r->revenue;
                $cost = (float) $r->cost;
                $profit = $revenue - $cost;

                return [
                    'mode' => $r->mode,
                    'lines' => (int) $r->lines,
                    'volume' => (float) $r->volume,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => round($profit, 2),
                    'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
                    'ticket_count' => (int) $r->ticket_count,
                    'avg_unit_price' => round((float) $r->avg_unit_price, 2),
                ];
            })->all();

        // === SUMMARY ===========================================================
        $totalRevenue = array_sum(array_column($tiers, 'revenue'));
        $totalVolume = array_sum(array_column($tiers, 'volume'));
        $catalogRevenue = array_sum(array_map(
            fn ($t) => $t['discount_kind'] === 'catalog' ? $t['revenue'] : 0,
            $tiers
        ));
        $catalogVolume = array_sum(array_map(
            fn ($t) => $t['discount_kind'] === 'catalog' ? $t['volume'] : 0,
            $tiers
        ));
        $discountedRevenue = array_sum(array_map(
            fn ($t) => in_array($t['discount_kind'], ['discounted', 'preferential'], true) ? $t['revenue'] : 0,
            $tiers
        ));
        $lostToDiscounts = array_sum(array_map(function ($t) {
            if (! in_array($t['discount_kind'], ['discounted', 'preferential'], true)) {
                return 0;
            }
            if ($t['original_unit_price'] === null) {
                return 0;
            }

            return ($t['original_unit_price'] - $t['unit_price']) * $t['volume'];
        }, $tiers));

        return [
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'catalog_price' => $product->price !== null ? (float) $product->price : null,
                'catalog_cost' => $product->cost_price !== null ? (float) $product->cost_price : null,
            ],
            'range' => [
                'from' => $range->start->toDateString(),
                'to' => $range->end->toDateString(),
            ],
            'by_price' => $tiers,
            'by_customer' => $byCustomer,
            'by_sale_type' => $bySaleType,
            'summary' => [
                'tiers_count' => count($tiers),
                'customers_count' => count(array_filter($byCustomer, fn ($c) => $c['customer_id'] !== null)),
                'volume_total' => round($totalVolume, 3),
                'revenue_total' => round($totalRevenue, 2),
                'catalog_revenue' => round($catalogRevenue, 2),
                'discounted_revenue' => round($discountedRevenue, 2),
                'lost_to_discounts' => round($lostToDiscounts, 2),
                'share_at_catalog_pct' => $totalVolume > 0 ? round(($catalogVolume / $totalVolume) * 100, 1) : null,
            ],
        ];
    }

    /**
     * Para cada tier 'preferential', busca el cliente con mayor volumen
     * en ese tier y lo añade como `top_customer`.
     *
     * @param  list<array<string,mixed>>  $tiers
     * @param  list<string>  $statusValues
     * @return list<array<string,mixed>>
     */
    private function attachTopCustomerToPreferentialTiers(
        array $tiers,
        int $productId,
        int $tenantId,
        ?int $branchId,
        DateRange $range,
        array $statusValues
    ): array {
        $needsLookup = array_filter($tiers, fn ($t) => $t['discount_kind'] === 'preferential');
        if (empty($needsLookup)) {
            return $tiers;
        }

        return array_map(function ($tier) use ($productId, $tenantId, $branchId, $range, $statusValues) {
            if ($tier['discount_kind'] !== 'preferential') {
                return $tier;
            }

            $q = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
                ->where('si.product_id', $productId)
                ->where('s.tenant_id', $tenantId)
                ->whereNull('s.deleted_at')
                ->whereBetween(DB::raw('COALESCE(s.completed_at, s.created_at)'), [$range->start, $range->end])
                ->where('si.unit_price', $tier['unit_price'])
                ->whereNotNull('s.customer_id');

            if ($branchId !== null) {
                $q->where('s.branch_id', $branchId);
            }
            if (! empty($statusValues)) {
                $q->whereIn('s.status', $statusValues);
                if (! in_array(SaleStatus::Cancelled->value, $statusValues, true)) {
                    $q->whereNull('s.cancelled_at');
                }
            }

            $row = $q->selectRaw('s.customer_id, MAX(c.name) as name, SUM(si.quantity) as volume, COUNT(DISTINCT s.id) as tickets')
                ->groupBy('s.customer_id')
                ->orderByDesc('volume')
                ->first();

            if ($row) {
                $tier['top_customer'] = [
                    'id' => (int) $row->customer_id,
                    'name' => (string) $row->name,
                    'volume' => (float) $row->volume,
                    'tickets' => (int) $row->tickets,
                ];
            }

            return $tier;
        }, $tiers);
    }

    /**
     * Convierte volumen a unidad base (kg/l) si la línea es peso/volumen,
     * de lo contrario quantity_unit es piezas y devuelve null.
     */
    private function volumeInBaseUnit(float $volume, ?string $presentationUnit, ?float $content, ?string $quantityUnit): ?float
    {
        if ($presentationUnit && $content !== null) {
            return match ($presentationUnit) {
                'kg', 'l' => round($volume * $content, 3),
                'g', 'ml' => round($volume * $content / 1000, 3),
                default => null,
            };
        }

        return match ($quantityUnit) {
            'kg', 'l' => round($volume, 3),
            'g', 'ml' => round($volume / 1000, 3),
            default => null,
        };
    }

    /**
     * @param  list<string>  $statuses
     * @return list<string>
     */
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
}
