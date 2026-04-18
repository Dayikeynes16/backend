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
