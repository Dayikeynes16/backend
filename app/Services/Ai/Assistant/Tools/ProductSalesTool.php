<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\Metrics\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Ventas de UN producto en un periodo, con desglose por precio de venta:
 * responde "¿cuántos kilos de pulpa vendí hoy y a qué precios?" — incluyendo
 * si hubo ventas a precios distintos (preferenciales o modificados).
 *
 * Consulta tipada sobre sale_items (JAMÁS SQL libre del modelo). Fecha canónica
 * COALESCE(completed_at, created_at) — misma que Métricas. El producto se
 * resuelve por nombre con la búsqueda difusa compartida y los items se filtran
 * por el nombre denormalizado (cubre históricos y todas las sucursales del
 * scope). Branch forzado para admin-sucursal/cajero vía resolveBranch().
 */
class ProductSalesTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_ventas_producto';
    }

    public function description(): string
    {
        return 'Devuelve cuánto se vendió de UN producto en un periodo: cantidad total (kg/piezas), ingreso, número de tickets y el DESGLOSE POR PRECIO de venta (detecta ventas del mismo producto a precios distintos). Usar para "¿cuántos kilos de pulpa vendí hoy?", "¿a qué precios se vendió el bistec esta semana?".';
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
                'product_name' => ['type' => ['string', 'null'], 'description' => 'Nombre del producto.'],
                'scope' => ['type' => 'string', 'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'], 'description' => 'Periodo.'],
                'date_from' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD solo con scope=custom.'],
                'date_to' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD solo con scope=custom.'],
                'branch_name' => ['type' => ['string', 'null'], 'description' => 'Sucursal (admin-empresa; null = todas).'],
            ],
            'required' => ['product_name', 'scope', 'date_from', 'date_to', 'branch_name'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $scope = (string) ($params['scope'] ?? 'today');
        [$from, $to] = $this->resolveDateRange($scope, $params['date_from'] ?? null, $params['date_to'] ?? null);
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);

        $name = trim((string) ($params['product_name'] ?? ''));
        $pool = Product::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->limit(500)
            ->get(['id', 'name', 'unit_type', 'price', 'branch_id', 'tenant_id']);
        $match = $this->fuzzyMatchByName($pool, $name, fn (Product $p) => $p->name);

        return [
            'scope' => $scope,
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'product' => $match['match'] ? ['id' => $match['match']->id, 'name' => $match['match']->name, 'unit_type' => $match['match']->unit_type, 'current_price' => (float) $match['match']->price] : null,
            'product_candidates' => array_map(fn (Product $p) => $p->name, $match['candidates']),
            'product_name' => $name,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        if (! $params['product']) {
            $data = [
                'date_from' => $params['date_from'],
                'date_to' => $params['date_to'],
                'branch_name' => $params['branch_name'],
                'product_name' => $params['product_name'],
                'found' => false,
                'candidates' => $params['product_candidates'],
            ];
            $summary = $params['product_candidates']
                ? 'No identifiqué el producto con certeza. Candidatos: '.implode(', ', $params['product_candidates']).'. Pregunta al usuario a cuál se refiere.'
                : 'No existe un producto con ese nombre en el catálogo del alcance del usuario.';

            return new ToolResult('product_sales', $data, $summary, $params);
        }

        $range = DateRange::custom($params['date_from'], $params['date_to']);
        $productName = $params['product']['name'];

        // Items de ventas contables no canceladas del periodo, emparejados por
        // el nombre denormalizado (cubre históricos y todas las sucursales).
        $base = DB::table('sale_items as i')
            ->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.tenant_id', app('tenant')->id)
            ->when($params['branch_id'], fn ($q) => $q->where('s.branch_id', $params['branch_id']))
            ->where('s.status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('s.cancelled_at')
            ->where(fn ($q) => $q->where('s.origin', '!=', 'web')->orWhereNotIn('s.status', ['pending', 'fulfilled']))
            ->whereBetween(DB::raw('COALESCE(s.completed_at, s.created_at)'), [$range->start, $range->end])
            ->whereRaw('LOWER(i.product_name) = ?', [mb_strtolower($productName)]);

        $breakdown = (clone $base)
            ->selectRaw('i.unit_price, COALESCE(SUM(i.quantity), 0) as qty, COALESCE(SUM(i.subtotal), 0) as revenue, COUNT(DISTINCT s.id) as tickets')
            ->groupBy('i.unit_price')
            ->orderByDesc(DB::raw('SUM(i.quantity)'))
            ->get()
            ->map(fn ($r) => [
                'unit_price' => round((float) $r->unit_price, 2),
                'quantity' => round((float) $r->qty, 3),
                'revenue' => round((float) $r->revenue, 2),
                'tickets' => (int) $r->tickets,
            ])
            ->values();

        $totalQty = round((float) $breakdown->sum('quantity'), 3);
        $totalRevenue = round((float) $breakdown->sum('revenue'), 2);

        $data = [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'branch_name' => $params['branch_name'],
            'found' => true,
            'product_name' => $productName,
            'unit_type' => $params['product']['unit_type'],
            'current_price' => $params['product']['current_price'],
            'total_quantity' => $totalQty,
            'total_revenue' => $totalRevenue,
            'avg_price' => $totalQty > 0 ? round($totalRevenue / $totalQty, 2) : null,
            'price_breakdown' => $breakdown->all(),
        ];

        $unit = $params['product']['unit_type'] ?: 'unidades';
        $lines = $breakdown->map(fn ($b) => number_format($b['quantity'], 3)." {$unit} a \$".number_format($b['unit_price'], 2).' ($'.number_format($b['revenue'], 2).' en '.$b['tickets'].' tickets)')->implode('; ');
        $summary = sprintf(
            'Producto "%s" del %s al %s%s: %s %s vendidos por $%s. Desglose por precio: %s. Precio de lista actual: $%s.',
            $productName,
            $params['date_from'],
            $params['date_to'],
            $params['branch_name'] ? ' en '.$params['branch_name'] : ' (todas las sucursales)',
            number_format($totalQty, 3),
            $unit,
            number_format($totalRevenue, 2),
            $lines ?: 'sin ventas en el periodo',
            number_format($params['product']['current_price'], 2),
        );

        return new ToolResult('product_sales', $data, $summary, $params);
    }
}
