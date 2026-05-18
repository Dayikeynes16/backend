<?php

namespace App\Services\Ai\Assistant;

use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Base que centraliza patrones comunes a todas las tools: resolución de
 * sucursal por nombre (case-insensitive), reescritura forzada de `branch_id`
 * para admin-sucursal, parseo de rangos de fecha relativos.
 *
 * NUNCA confiar en el `branch_id` o `branch_name` que devuelva el modelo si
 * el usuario es admin-sucursal: se reemplaza por `$user->branch_id`.
 */
abstract class AbstractAssistantTool implements AssistantTool
{
    public function readOnly(): bool
    {
        return true;
    }

    public function authorize(User $user, array $params): bool
    {
        foreach ($this->rolesAllowed() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resuelve un nombre de sucursal opcional a un Branch del tenant actual.
     * Si el usuario es admin-sucursal, se FUERZA a su sucursal sin importar
     * lo que diga el modelo. Si es admin-empresa y no especifica, devuelve
     * null (= todas las sucursales del tenant).
     */
    protected function resolveBranch(User $user, ?string $branchName): ?Branch
    {
        // Admin-sucursal: ignorar lo que diga el modelo, usar su propia sucursal.
        if ($user->hasRole('admin-sucursal')) {
            return $user->branch_id ? Branch::find($user->branch_id) : null;
        }

        $name = trim((string) $branchName);
        if ($name === '') {
            return null;
        }

        return Branch::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * Convierte un scope textual a [fecha_inicio, fecha_fin] en zona del app.
     * Si scope = 'custom', usa $dateFrom/$dateTo; si no, se ignoran.
     *
     * @return array{0: string, 1: string} YYYY-MM-DD inclusivo en ambos extremos
     */
    protected function resolveDateRange(string $scope, ?string $dateFrom, ?string $dateTo): array
    {
        $tz = config('app.timezone');
        $today = CarbonImmutable::now($tz);

        return match ($scope) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [$today->subDay()->toDateString(), $today->subDay()->toDateString()],
            'this_week' => [$today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()],
            'last_week' => [$today->subWeek()->startOfWeek()->toDateString(), $today->subWeek()->endOfWeek()->toDateString()],
            'this_month' => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()],
            'last_month' => [$today->subMonth()->startOfMonth()->toDateString(), $today->subMonth()->endOfMonth()->toDateString()],
            'custom' => $this->sanitizeCustomRange($dateFrom, $dateTo, $today),
            default => [$today->toDateString(), $today->toDateString()],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sanitizeCustomRange(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $tz = config('app.timezone');
        $start = $from ? CarbonImmutable::parse($from, $tz) : $today;
        $end = $to ? CarbonImmutable::parse($to, $tz) : $start;

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->toDateString(), $end->toDateString()];
    }
}
