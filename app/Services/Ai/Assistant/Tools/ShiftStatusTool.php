<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\CashRegisterShift;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;

class ShiftStatusTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_turnos';
    }

    public function description(): string
    {
        return 'Devuelve turnos de caja abiertos en este momento y los cortes recientes (últimos cerrados). Usar para "¿qué turnos están abiertos?", "muéstrame los cortes de hoy".';
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
                'branch_name' => ['type' => ['string', 'null']],
                'recent_limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'description' => 'Cuántos cortes recientes (ya cerrados) devolver. Default 5.',
                ],
            ],
            'required' => ['branch_name', 'recent_limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $limit = (int) ($params['recent_limit'] ?? 5);
        $limit = max(1, min(10, $limit));

        return [
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'recent_limit' => $limit,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        // El cajero solo ve SUS turnos y SUS cortes (no los totales de otros).
        $base = fn () => CashRegisterShift::query()
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
            ->when($user->hasRole('cajero'), fn ($q) => $q->where('user_id', $user->id))
            ->with(['user:id,name', 'branch:id,name']);

        $open = $base()
            ->whereNull('closed_at')
            ->orderByDesc('opened_at')
            ->get()
            ->map(fn (CashRegisterShift $s) => [
                'id' => $s->id,
                'branch' => $s->branch?->name,
                'cashier' => $s->user?->name,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'opening_amount' => (float) $s->opening_amount,
            ])
            ->values()
            ->all();

        $recent = $base()
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->limit($params['recent_limit'])
            ->get()
            ->map(fn (CashRegisterShift $s) => [
                'id' => $s->id,
                'branch' => $s->branch?->name,
                'cashier' => $s->user?->name,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'closed_at' => $s->closed_at?->toIso8601String(),
                'total_sales' => (float) $s->total_sales,
                'declared_amount' => (float) $s->declared_amount,
                'difference' => (float) $s->difference,
            ])
            ->values()
            ->all();

        $data = [
            'branch_name' => $params['branch_name'],
            'open_shifts' => $open,
            'recent_closed_shifts' => $recent,
        ];

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $summary = sprintf(
            'Turnos abiertos en %s: %d. Cortes recientes: %d.',
            $branchLabel,
            count($open),
            count($recent),
        );

        return new ToolResult('shift_status', $data, $summary, $params);
    }
}
