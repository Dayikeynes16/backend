<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use Illuminate\Support\Facades\DB;

class CustomerStatsTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_clientes';
    }

    public function description(): string
    {
        return 'Devuelve estadística agregada de clientes: total con saldo pendiente, top clientes por monto adeudado o por compras. Usar para "¿cuánto me deben los clientes?", "qué clientes me compran más".';
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
                'metric' => [
                    'type' => 'string',
                    'enum' => ['outstanding_debt', 'top_buyers'],
                    'description' => 'outstanding_debt = clientes con saldo pendiente. top_buyers = clientes con mayor monto de compras completadas.',
                ],
                'branch_name' => ['type' => ['string', 'null']],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                    'description' => 'Cuántos clientes devolver. Default 5.',
                ],
            ],
            'required' => ['metric', 'branch_name', 'limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $limit = (int) ($params['limit'] ?? 5);
        $limit = max(1, min(20, $limit));

        return [
            'metric' => (string) ($params['metric'] ?? 'outstanding_debt'),
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'limit' => $limit,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        return match ($params['metric']) {
            'outstanding_debt' => $this->outstandingDebt($params),
            'top_buyers' => $this->topBuyers($params),
            default => $this->outstandingDebt($params),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function outstandingDebt(array $params): ToolResult
    {
        // amount_pending vive en sales. Sumamos por customer en estado pending o completed-con-saldo.
        $rows = DB::table('sales as s')
            ->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.tenant_id', app('tenant')->id)
            ->when($params['branch_id'], fn ($q) => $q->where('s.branch_id', $params['branch_id']))
            ->whereNull('s.deleted_at')
            ->whereIn('s.status', [SaleStatus::Pending->value, SaleStatus::Completed->value])
            ->whereNull('s.cancelled_at')
            ->where('s.amount_pending', '>', 0)
            ->select('c.id', 'c.name', DB::raw('SUM(s.amount_pending) as debt'), DB::raw('COUNT(s.id) as sale_count'))
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('debt')
            ->limit($params['limit'])
            ->get();

        $totalDebt = (float) Sale::query()
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
            ->whereIn('status', [SaleStatus::Pending->value, SaleStatus::Completed->value])
            ->whereNull('cancelled_at')
            ->where('amount_pending', '>', 0)
            ->sum('amount_pending');

        $data = [
            'metric' => 'outstanding_debt',
            'branch_name' => $params['branch_name'],
            'total_debt' => $totalDebt,
            'top_customers' => $rows->map(fn ($r) => [
                'customer_id' => (int) $r->id,
                'name' => (string) $r->name,
                'debt' => (float) $r->debt,
                'sale_count' => (int) $r->sale_count,
            ])->all(),
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $summary = sprintf(
            'Saldo total por cobrar en %s: $%s. Top %d clientes con deuda.',
            $branchLabel,
            number_format($totalDebt, 2),
            count($data['top_customers']),
        );

        return new ToolResult('customer_debt', $data, $summary, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function topBuyers(array $params): ToolResult
    {
        $rows = Customer::query()
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
            ->withSum([
                'sales as total_bought' => function ($q) use ($params) {
                    $q->where('status', SaleStatus::Completed->value)
                        ->whereNull('cancelled_at')
                        ->when($params['branch_id'], fn ($qq) => $qq->where('branch_id', $params['branch_id']));
                },
            ], 'total')
            ->orderByDesc('total_bought')
            ->limit($params['limit'])
            ->get();

        $data = [
            'metric' => 'top_buyers',
            'branch_name' => $params['branch_name'],
            'customers' => $rows->map(fn (Customer $c) => [
                'customer_id' => $c->id,
                'name' => $c->name,
                'total_bought' => (float) ($c->total_bought ?? 0),
            ])->values()->all(),
        ];

        $summary = sprintf('Top %d clientes por compras completadas.', count($data['customers']));

        return new ToolResult('customer_top_buyers', $data, $summary, $params);
    }
}
