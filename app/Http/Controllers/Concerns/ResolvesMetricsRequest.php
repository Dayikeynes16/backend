<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use App\Services\Metrics\DateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesMetricsRequest
{
    protected function resolveDateRange(Request $request): DateRange
    {
        return DateRange::fromRequest(
            $request->query('preset'),
            $request->query('from'),
            $request->query('to'),
        );
    }

    protected function resolveSucursalBranchId(Request $request): int
    {
        $user = $request->user();
        if (! $user->branch_id) {
            throw new HttpException(403, 'User has no branch assigned');
        }

        return (int) $user->branch_id;
    }

    protected function resolveEmpresaBranchId(Request $request, int $tenantId): ?int
    {
        $raw = $request->query('branch_id');

        if ($raw === null || $raw === '' || $raw === 'all') {
            return null;
        }

        $request->merge(['branch_id' => $raw]);
        $validator = validator($request->only('branch_id'), [
            'branch_id' => [
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ]);

        if ($validator->fails()) {
            throw new HttpException(403, 'Invalid branch_id');
        }

        return (int) $raw;
    }

    protected function wantsRefresh(Request $request): bool
    {
        return $request->boolean('refresh');
    }

    /**
     * Resuelve qué estados de venta incluir en las métricas.
     * Default: ['completed'] (caja base, comportamiento histórico).
     * Acepta query param `statuses=completed,pending,cancelled`.
     */
    protected function resolveStatuses(Request $request): array
    {
        $raw = $request->query('statuses', 'completed');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        $allowed = ['completed', 'pending', 'cancelled'];
        $clean = array_values(array_unique(array_intersect(
            $allowed,
            array_map('strtolower', array_map('trim', $list))
        )));

        return ! empty($clean) ? $clean : ['completed'];
    }

    protected function compareEnabled(Request $request): bool
    {
        return $request->boolean('compare', true);
    }

    protected function tenantId(): int
    {
        return app('tenant')->id;
    }

    protected function commonProps(Request $request, DateRange $range, ?int $branchId = null): array
    {
        return [
            'range' => $range->toArray(),
            'presets' => DateRange::PRESETS,
            'compare' => $this->compareEnabled($request),
            'refresh' => $this->wantsRefresh($request),
            'selected_branch_id' => $branchId,
            'tenant' => app('tenant'),
        ];
    }

    protected function branchOptions(int $tenantId): array
    {
        return Branch::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }
}
