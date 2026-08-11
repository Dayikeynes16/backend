<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MergesCustomerPreferentialPrices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando **legacy**: existía para limpiar la cartera antes de instalar el
 * índice `customers_tenant_branch_phone_uniq` (migración 2026_04_17_000005).
 * Con ese índice vigente ya no se pueden insertar dos clientes con el mismo
 * teléfono exacto en la misma sucursal, así que no encontrará nada que fusionar.
 *
 * Los duplicados que sí siguen apareciendo son los que difieren en FORMATO
 * ('993 123 4567' vs '+529931234567'): valores distintos para el índice y para
 * este comando. Ésos los resuelve `customers:normalize-phones`.
 */
class DedupCustomers extends Command
{
    use MergesCustomerPreferentialPrices;

    protected $signature = 'customers:dedup {--dry-run=true : Report without modifying data}';

    protected $description = 'Deduplicate customers grouping by (tenant_id, branch_id, phone), keep oldest, reassign relations';

    public function handle(): int
    {
        $dryRun = filter_var($this->option('dry-run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dryRun = $dryRun === null ? true : $dryRun;

        $this->info($dryRun ? '=== DRY RUN — no changes will be persisted ===' : '=== APPLYING CHANGES ===');

        // Este comando agrupa por teléfono EXACTO, así que es ciego a los
        // duplicados que solo difieren en formato ('993 123 4567' vs
        // '+529931234567'). Ésos los resuelve customers:normalize-phones.
        $unnormalized = DB::table('customers')
            ->whereNotNull('phone')
            ->where('phone', 'not like', '+%')
            ->count();

        if ($unnormalized > 0) {
            $this->warn("Hay {$unnormalized} teléfonos sin normalizar. Corre primero: php artisan customers:normalize-phones --dry-run=false");
            $this->warn('Este comando agrupa por teléfono exacto y NO detectará duplicados que solo difieren en formato.');
        }

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

                $reassignedPrices += $this->mergePreferentialPrices($group->keep_id, $duplicates);

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
