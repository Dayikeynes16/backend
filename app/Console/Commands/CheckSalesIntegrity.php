<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Chequeo de solo lectura: ¿el total de cada venta coincide con la suma de sus
 * líneas?
 *
 * Importa porque asignar un cliente a una venta **recalcula el total desde las
 * líneas** (`AssignCustomerToSale`), sin fiarse del valor guardado. Si alguna
 * venta tuviera un total que sus líneas no respaldan, asignarle cliente lo
 * cambiaría. En condiciones normales esto no pasa: `SaleItemEditor` recalcula
 * el total en cada alta, edición y baja de línea.
 *
 * No modifica nada. Seguro de correr en producción.
 */
class CheckSalesIntegrity extends Command
{
    protected $signature = 'sales:check-integrity
                            {--branch= : Limitar a una sucursal}
                            {--show=10 : Cuántos ejemplos mostrar de cada problema}';

    protected $description = 'Verifica que el total de cada venta coincida con la suma de sus lineas (solo lectura)';

    public function handle(): int
    {
        $branch = $this->option('branch');
        $show = max(1, (int) $this->option('show'));

        $this->info('=== Integridad de totales de venta (solo lectura) ===');

        $totalSales = DB::table('sales')
            ->whereNull('deleted_at')
            ->when($branch, fn ($q) => $q->where('branch_id', (int) $branch))
            ->count();

        $this->line("Ventas analizadas: {$totalSales}");

        // 1. Ventas sin ninguna línea viva pero con importe.
        $orphanTotals = DB::table('sales as s')
            ->whereNull('s.deleted_at')
            ->when($branch, fn ($q) => $q->where('s.branch_id', (int) $branch))
            ->where('s.total', '>', 0)
            ->whereRaw('NOT EXISTS (select 1 from sale_items i where i.sale_id = s.id and i.deleted_at is null)')
            ->orderBy('s.id')
            ->get(['s.id', 's.folio', 's.total', 's.status']);

        // 2. Ventas cuyo total no coincide con la suma de sus líneas.
        $mismatched = DB::table('sales as s')
            ->whereNull('s.deleted_at')
            ->when($branch, fn ($q) => $q->where('s.branch_id', (int) $branch))
            ->whereRaw('EXISTS (select 1 from sale_items i where i.sale_id = s.id and i.deleted_at is null)')
            ->whereRaw('ABS(s.total - (select COALESCE(SUM(i.subtotal), 0) from sale_items i where i.sale_id = s.id and i.deleted_at is null)) >= 0.01')
            ->orderBy('s.id')
            ->get(['s.id', 's.folio', 's.total', 's.status']);

        $this->newLine();

        if ($orphanTotals->isEmpty()) {
            $this->info('✓ Ninguna venta tiene importe sin lineas que lo respalden.');
        } else {
            $this->error("✗ {$orphanTotals->count()} venta(s) con importe pero sin lineas.");
            $this->warn('  Asignarles un cliente pondria su total en 0.');
            foreach ($orphanTotals->take($show) as $sale) {
                $this->line("    {$sale->folio} (#{$sale->id}) total={$sale->total} status={$sale->status}");
            }
            if ($orphanTotals->count() > $show) {
                $this->line('    ... y '.($orphanTotals->count() - $show).' mas');
            }
        }

        $this->newLine();

        if ($mismatched->isEmpty()) {
            $this->info('✓ El total de cada venta coincide con la suma de sus lineas.');
        } else {
            $this->error("✗ {$mismatched->count()} venta(s) con total distinto a la suma de sus lineas.");
            $this->warn('  Asignarles un cliente ajustaria su total al valor de las lineas.');
            foreach ($mismatched->take($show) as $sale) {
                $lines = DB::table('sale_items')
                    ->where('sale_id', $sale->id)
                    ->whereNull('deleted_at')
                    ->sum('subtotal');
                $this->line("    {$sale->folio} (#{$sale->id}) total={$sale->total} lineas={$lines} status={$sale->status}");
            }
            if ($mismatched->count() > $show) {
                $this->line('    ... y '.($mismatched->count() - $show).' mas');
            }
        }

        $this->newLine();

        $problems = $orphanTotals->count() + $mismatched->count();

        if ($problems === 0) {
            $this->info('Todo coherente. Asignar clientes no alterara ningun total.');
        } else {
            $this->warn("{$problems} venta(s) a revisar. Este comando no modifica nada.");
        }

        return self::SUCCESS;
    }
}
