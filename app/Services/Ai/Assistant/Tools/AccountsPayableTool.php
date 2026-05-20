<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas por pagar: saldo total adeudado + top proveedores con deuda.
 * Para admin-sucursal sólo cuenta las compras de su branch.
 */
class AccountsPayableTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_cuentas_por_pagar';
    }

    public function description(): string
    {
        return 'Devuelve el saldo total que debes a proveedores y el top de proveedores con mayor adeudo. Usar para "¿cuánto le debo a mis proveedores?", "a quién le debo más", "saldo de proveedores".';
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
                'branch_name' => ['type' => ['string', 'null']],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                    'description' => 'Cuántos top proveedores devolver. Default 5.',
                ],
            ],
            'required' => ['branch_name', 'limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $limit = (int) ($params['limit'] ?? 5);
        $limit = max(1, min(20, $limit));

        return [
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'limit' => $limit,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        $base = fn () => Purchase::query()
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->where('amount_pending', '>', 0)
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']));

        $totalDebt = (float) $base()->sum('amount_pending');
        $purchaseCount = (int) $base()->count();

        $topProviders = $base()
            ->select('provider_id', DB::raw('SUM(amount_pending) as debt'), DB::raw('COUNT(*) as purchase_count'))
            ->groupBy('provider_id')
            ->orderByDesc('debt')
            ->limit($params['limit'])
            ->with('provider:id,name')
            ->get()
            ->map(fn ($r) => [
                'provider_id' => (int) $r->provider_id,
                'provider_name' => $r->provider?->name ?? '—',
                'debt' => (float) $r->debt,
                'purchase_count' => (int) $r->purchase_count,
            ])
            ->values()
            ->all();

        $data = [
            'branch_name' => $params['branch_name'],
            'total_debt' => $totalDebt,
            'purchase_count' => $purchaseCount,
            'top_providers' => $topProviders,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $summary = sprintf(
            'Debes $%s a %d proveedor%s en %s (%d compras pendientes).',
            number_format($totalDebt, 2),
            count($topProviders),
            count($topProviders) === 1 ? '' : 'es',
            $branchLabel,
            $purchaseCount,
        );

        return new ToolResult('accounts_payable', $data, $summary, $params);
    }
}
