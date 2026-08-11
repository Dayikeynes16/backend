<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MergesCustomerPreferentialPrices;
use App\Services\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja `customers.phone` en E.164 y fusiona los clientes que solo diferían en
 * el formato del número. Prerrequisito del mutator de `Customer::phone`: sin
 * esto, la primera edición de un cliente cuyo número normalizado ya existe
 * choca contra `customers_tenant_branch_phone_uniq`.
 *
 * Se conserva el registro más antiguo de cada grupo (menor id) y hereda ventas
 * y precios preferenciales de los fusionados — misma política que
 * `customers:dedup`.
 *
 * La cartera es **por sucursal**: el agrupamiento incluye `branch_id`, así que
 * el mismo número en dos sucursales sigue siendo dos clientes distintos.
 */
class NormalizeCustomerPhones extends Command
{
    use MergesCustomerPreferentialPrices;

    protected $signature = 'customers:normalize-phones {--dry-run=true : Reporta sin modificar datos}';

    protected $description = 'Normaliza customers.phone a E.164 y fusiona duplicados por formato';

    public function handle(): int
    {
        $dryRun = filter_var($this->option('dry-run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $this->info($dryRun ? '=== DRY RUN — no se escribe nada ===' : '=== APLICANDO CAMBIOS ===');

        $rows = DB::table('customers')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'branch_id', 'phone', 'name']);

        /** @var array<string, array<int, object>> $groups */
        $groups = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $normalized = PhoneNormalizer::normalize($row->phone);

            // Lo que no parece un teléfono se deja exactamente como está. No
            // basta con que no destruya el dato: normalizar '344' a '+344'
            // haría que dos clientes distintos con basura coincidente se
            // fusionaran en uno, y esa fusión no se deshace.
            if (! PhoneNormalizer::isPlausible($normalized)) {
                $this->warn("  #{$row->id} '{$row->name}': '{$row->phone}' no parece un teléfono — se deja intacto.");
                $skipped++;

                continue;
            }

            $groups["{$row->tenant_id}|{$row->branch_id}|{$normalized}"][] = $row;
        }

        if ($skipped > 0) {
            $this->newLine();
            $this->warn("{$skipped} registro(s) con teléfono no reconocible quedan sin tocar. Revísalos a mano si hace falta.");
            $this->newLine();
        }

        $merged = 0;
        $rewritten = 0;

        foreach ($groups as $key => $group) {
            $normalized = explode('|', $key)[2];
            $keep = $group[0];
            $duplicates = array_slice($group, 1);

            if ($duplicates !== []) {
                $ids = implode(',', array_map(fn ($d) => $d->id, $duplicates));
                $this->line("  fusionar [{$ids}] → #{$keep->id} ('{$keep->name}') phone={$normalized}");
            } elseif ($keep->phone !== $normalized) {
                $this->line("  #{$keep->id} '{$keep->phone}' → '{$normalized}'");
            } else {
                continue;
            }

            if ($dryRun) {
                $merged += count($duplicates);
                $rewritten++;

                continue;
            }

            DB::transaction(function () use ($keep, $duplicates, $normalized, &$merged, &$rewritten) {
                if ($duplicates !== []) {
                    $ids = array_map(fn ($d) => $d->id, $duplicates);

                    DB::table('sales')->whereIn('customer_id', $ids)->update(['customer_id' => $keep->id]);
                    $this->mergePreferentialPrices($keep->id, $ids);
                    DB::table('customers')->whereIn('id', $ids)->delete();

                    $merged += count($ids);
                }

                DB::table('customers')->where('id', $keep->id)->update([
                    'phone' => $normalized,
                    'updated_at' => now(),
                ]);
                $rewritten++;
            });
        }

        $this->info($dryRun
            ? "Dry run: {$rewritten} teléfonos a reescribir, {$merged} clientes a fusionar. Re-ejecuta con --dry-run=false."
            : "Listo. {$rewritten} teléfonos normalizados, {$merged} clientes fusionados.");

        return self::SUCCESS;
    }
}
