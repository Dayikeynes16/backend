<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DedupCustomers extends Command
{
    protected $signature = 'customers:dedup {--dry-run=true : Report without modifying data}';

    protected $description = 'Deduplicate customers grouping by (tenant_id, branch_id, phone), keep oldest, reassign relations';

    public function handle(): int
    {
        $dryRun = filter_var($this->option('dry-run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dryRun = $dryRun === null ? true : $dryRun;

        $this->info($dryRun ? '=== DRY RUN — no changes will be persisted ===' : '=== APPLYING CHANGES ===');

        $duplicateGroups = DB::table('customers')
            ->select('tenant_id', 'branch_id', 'phone', DB::raw('COUNT(*) as total'), DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('phone')
            ->groupBy('tenant_id', 'branch_id', 'phone')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info('No duplicates found. Safe to apply the unique index.');

            return self::SUCCESS;
        }

        $totalDuplicates = $duplicateGroups->sum(fn ($g) => $g->total - 1);
        $this->warn("Found {$duplicateGroups->count()} groups with duplicates ({$totalDuplicates} extra rows)");

        $reassignedSales = 0;
        $reassignedPrices = 0;
        $deletedCustomers = 0;

        foreach ($duplicateGroups as $group) {
            $duplicates = DB::table('customers')
                ->where('tenant_id', $group->tenant_id)
                ->where('branch_id', $group->branch_id)
                ->where('phone', $group->phone)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id');

            $this->line("  tenant={$group->tenant_id} branch={$group->branch_id} phone={$group->phone} keep=#{$group->keep_id} merge=".$duplicates->implode(','));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($group, $duplicates, &$reassignedSales, &$reassignedPrices, &$deletedCustomers) {
                $reassignedSales += DB::table('sales')
                    ->whereIn('customer_id', $duplicates)
                    ->update(['customer_id' => $group->keep_id]);

                $reassignedPrices += DB::table('customer_product_prices')
                    ->whereIn('customer_id', $duplicates)
                    ->update(['customer_id' => $group->keep_id]);

                $deletedCustomers += DB::table('customers')
                    ->whereIn('id', $duplicates)
                    ->delete();
            });
        }

        if ($dryRun) {
            $this->info('Dry run complete. Re-run with --dry-run=false to apply.');
        } else {
            $this->info("Done. Reassigned {$reassignedSales} sales, {$reassignedPrices} prices; deleted {$deletedCustomers} customers.");
        }

        return self::SUCCESS;
    }
}
