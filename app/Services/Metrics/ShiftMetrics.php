<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;

class ShiftMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $row = DB::table('cash_register_shifts')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$range->start, $range->end])
            ->selectRaw('
                COUNT(*) as closed_count,
                COALESCE(SUM(difference), 0) as diff_cash,
                COALESCE(SUM(difference_card), 0) as diff_card,
                COALESCE(SUM(difference_transfer), 0) as diff_transfer
            ')
            ->first();

        $withdrawals = DB::table('cash_withdrawals as w')
            ->join('cash_register_shifts as cs', 'cs.id', '=', 'w.shift_id')
            ->where('cs.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('cs.branch_id', $branchId))
            ->whereBetween('w.created_at', [$range->start, $range->end])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(w.amount), 0) as t')
            ->first();

        $biggestDiff = DB::table('cash_register_shifts as cs')
            ->join('users as u', 'u.id', '=', 'cs.user_id')
            ->where('cs.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('cs.branch_id', $branchId))
            ->whereNotNull('cs.closed_at')
            ->whereBetween('cs.closed_at', [$range->start, $range->end])
            ->orderByRaw('ABS(COALESCE(cs.difference, 0) + COALESCE(cs.difference_card, 0) + COALESCE(cs.difference_transfer, 0)) DESC')
            ->select('cs.id', 'cs.closed_at', 'u.name as cashier', 'cs.difference', 'cs.difference_card', 'cs.difference_transfer')
            ->first();

        return [
            'closed_count' => (int) $row->closed_count,
            'total_cash_difference' => (float) $row->diff_cash,
            'total_card_difference' => (float) $row->diff_card,
            'total_transfer_difference' => (float) $row->diff_transfer,
            'total_difference' => (float) $row->diff_cash + (float) $row->diff_card + (float) $row->diff_transfer,
            'withdrawal_count' => (int) $withdrawals->c,
            'withdrawal_total' => (float) $withdrawals->t,
            'biggest_difference_shift' => $biggestDiff ? [
                'id' => (int) $biggestDiff->id,
                'closed_at' => $biggestDiff->closed_at,
                'cashier' => $biggestDiff->cashier,
                'difference' => (float) ($biggestDiff->difference ?? 0) + (float) ($biggestDiff->difference_card ?? 0) + (float) ($biggestDiff->difference_transfer ?? 0),
            ] : null,
        ];
    }

    public function dailyDifferences(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return DB::table('cash_register_shifts')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$range->start, $range->end])
            ->selectRaw('DATE(closed_at) as day, SUM(COALESCE(difference, 0) + COALESCE(difference_card, 0) + COALESCE(difference_transfer, 0)) as diff')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'difference' => (float) $r->diff])
            ->all();
    }

    public function shiftsTable(DateRange $range, ?int $branchId, int $tenantId, int $limit = 100): array
    {
        return DB::table('cash_register_shifts as cs')
            ->join('users as u', 'u.id', '=', 'cs.user_id')
            ->where('cs.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('cs.branch_id', $branchId))
            ->whereNotNull('cs.closed_at')
            ->whereBetween('cs.closed_at', [$range->start, $range->end])
            ->leftJoin(DB::raw('(SELECT shift_id, COALESCE(SUM(amount), 0) as withdrawals FROM cash_withdrawals GROUP BY shift_id) w'), 'w.shift_id', '=', 'cs.id')
            ->select([
                'cs.id', 'cs.opened_at', 'cs.closed_at',
                'u.name as cashier',
                'cs.opening_amount',
                'cs.expected_amount',
                'cs.declared_amount',
                'cs.difference',
                'cs.difference_card',
                'cs.difference_transfer',
                'w.withdrawals',
            ])
            ->orderByDesc('cs.closed_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'opened_at' => $r->opened_at,
                'closed_at' => $r->closed_at,
                'cashier' => $r->cashier,
                'opening_amount' => (float) $r->opening_amount,
                'expected_amount' => (float) $r->expected_amount,
                'declared_amount' => (float) $r->declared_amount,
                'difference' => (float) ($r->difference ?? 0),
                'difference_card' => (float) ($r->difference_card ?? 0),
                'difference_transfer' => (float) ($r->difference_transfer ?? 0),
                'withdrawals' => (float) ($r->withdrawals ?? 0),
            ])
            ->all();
    }
}
