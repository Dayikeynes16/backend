<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;

/**
 * Detalle de UN cliente: sus compras recientes con artículos (qué llevó,
 * cuánto y a qué precio), su deuda pendiente y sus últimos abonos. Responde
 * "¿cuál fue el pedido de Matías y qué llevó?".
 *
 * Consulta tipada (JAMÁS SQL libre). Cliente resuelto con la búsqueda difusa
 * compartida; branch forzado para admin-sucursal/cajero (mismo scope que el
 * cobro global). Disponible también para cajero (gestiona el fiado).
 */
class CustomerDetailTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_cliente_detalle';
    }

    public function description(): string
    {
        return 'Devuelve el detalle de UN cliente: sus ventas recientes con los artículos que llevó (producto, cantidad, precio), su deuda pendiente y sus últimos abonos. Usar para "¿qué llevó Matías en su último pedido?", "¿cuánto me debe la señora María y qué ha comprado?", "historial del cliente X".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-empresa', 'admin-sucursal', 'cajero'];
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'customer_name' => ['type' => ['string', 'null'], 'description' => 'Nombre del cliente.'],
                'sales_limit' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 10, 'description' => 'Cuántas ventas recientes detallar (default 5).'],
            ],
            'required' => ['customer_name', 'sales_limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $name = trim((string) ($params['customer_name'] ?? ''));

        $pool = Customer::query()
            ->when(
                ($user->hasRole('admin-sucursal') || $user->hasRole('cajero')) && $user->branch_id,
                fn ($q) => $q->where('branch_id', $user->branch_id),
            )
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'name', 'phone', 'branch_id', 'tenant_id']);

        $match = $this->fuzzyMatchByName($pool, $name, fn (Customer $c) => $c->name);

        return [
            'customer_id' => $match['match']?->id,
            'customer_name' => $match['match']?->name ?? $name,
            'customer_phone' => $match['match']?->phone,
            'customer_candidates' => array_map(fn (Customer $c) => $c->name, $match['candidates']),
            'sales_limit' => min(10, max(1, (int) ($params['sales_limit'] ?? 5))),
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        if (! $params['customer_id']) {
            $data = [
                'found' => false,
                'customer_name' => $params['customer_name'],
                'candidates' => $params['customer_candidates'],
            ];
            $summary = $params['customer_candidates']
                ? 'No identifiqué al cliente con certeza. Candidatos: '.implode(', ', $params['customer_candidates']).'. Pregunta al usuario a cuál se refiere.'
                : 'No existe un cliente con ese nombre en el alcance del usuario.';

            return new ToolResult('customer_detail', $data, $summary, $params);
        }

        $sales = Sale::query()
            ->where('customer_id', $params['customer_id'])
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->with('items:id,sale_id,product_name,quantity,unit_price,subtotal')
            ->orderByDesc('created_at')
            ->limit($params['sales_limit'])
            ->get();

        $totalOwed = round((float) Sale::query()
            ->where('customer_id', $params['customer_id'])
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->where('amount_pending', '>', 0)
            ->sum('amount_pending'), 2);

        $recentPayments = CustomerPayment::query()
            ->where('customer_id', $params['customer_id'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (CustomerPayment $p) => [
                'folio' => $p->folio,
                'method' => $p->method,
                'amount_applied' => (float) $p->amount_applied,
                'cancelled' => $p->cancelled_at !== null,
                'date' => $p->created_at?->toDateString(),
            ])
            ->values()
            ->all();

        $salesData = $sales->map(fn (Sale $s) => [
            'folio' => $s->folio,
            'date' => $s->created_at?->toDateString(),
            'total' => (float) $s->total,
            'amount_pending' => (float) $s->amount_pending,
            'status' => $s->status->value,
            'items' => $s->items->map(fn ($i) => [
                'product' => $i->product_name,
                'quantity' => round((float) $i->quantity, 3),
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
            ])->values()->all(),
        ])->values()->all();

        $data = [
            'found' => true,
            'customer_name' => $params['customer_name'],
            'customer_phone' => $params['customer_phone'],
            'total_owed' => $totalOwed,
            'sales' => $salesData,
            'recent_payments' => $recentPayments,
        ];

        $salesText = collect($salesData)->map(function ($s) {
            $items = collect($s['items'])->map(fn ($i) => $i['quantity'].' x '.$i['product'].' a $'.number_format($i['unit_price'], 2))->implode(', ');

            return "{$s['folio']} ({$s['date']}, \${$s['total']}".($s['amount_pending'] > 0 ? ', debe $'.$s['amount_pending'] : '').'): '.($items ?: 'sin artículos');
        })->implode(' | ');

        $summary = sprintf(
            'Cliente %s: debe $%s. Ventas recientes: %s.',
            $params['customer_name'],
            number_format($totalOwed, 2),
            $salesText ?: 'sin ventas registradas',
        );

        return new ToolResult('customer_detail', $data, $summary, $params);
    }
}
