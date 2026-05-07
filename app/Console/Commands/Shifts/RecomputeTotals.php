<?php

namespace App\Console\Commands\Shifts;

use App\Models\CashRegisterShift;
use App\Services\ShiftTotalsCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Backfill de los nuevos campos de turno (sales_generated_amount/_count,
 * collections_from_today_amount, collections_from_previous_amount) sobre
 * shifts cerrados antes de la migración.
 *
 * No-destructivo: no toca columnas legacy (total_sales, total_cash, etc.). Solo
 * popula los campos nuevos que estaban en NULL. Idempotente: re-ejecutarlo
 * recalcula con los mismos resultados.
 *
 * Uso:
 *   php artisan shifts:recompute-totals
 *   php artisan shifts:recompute-totals --since=2026-01-01
 *   php artisan shifts:recompute-totals --shift=42
 *   php artisan shifts:recompute-totals --dry-run
 */
#[Signature('shifts:recompute-totals
    {--since= : Recalcular desde esta fecha (YYYY-MM-DD), inclusivo}
    {--shift= : ID específico de un shift}
    {--dry-run : Mostrar lo que cambiaría sin tocar la BD}
')]
#[Description('Backfill de totales separados (ventas vs cobranza) en shifts cerrados.')]
class RecomputeTotals extends Command
{
    public function handle(ShiftTotalsCalculator $calculator): int
    {
        $query = CashRegisterShift::whereNotNull('closed_at');

        if ($shiftId = $this->option('shift')) {
            $query->where('id', $shiftId);
        }

        if ($since = $this->option('since')) {
            try {
                $sinceDate = Carbon::parse($since);
            } catch (\Throwable $e) {
                $this->error("Fecha inválida: {$since}");

                return self::INVALID;
            }
            $query->where('opened_at', '>=', $sinceDate);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('No hay shifts cerrados que coincidan con el filtro.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Recalculando {$total} shift(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $touched = 0;
        $query->chunkById(100, function ($shifts) use ($calculator, $dryRun, $bar, &$touched) {
            foreach ($shifts as $shift) {
                $totals = $calculator->compute(
                    $shift->branch_id,
                    $shift->user_id,
                    $shift->opened_at,
                    $shift->closed_at,
                );

                if (! $dryRun) {
                    $shift->update([
                        'sales_generated_amount' => $totals['sales_generated_amount'],
                        'sales_generated_count' => $totals['sales_generated_count'],
                        'collections_from_today_amount' => $totals['collections_from_today_amount'],
                        'collections_from_previous_amount' => $totals['collections_from_previous_amount'],
                    ]);
                }
                $touched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Listo. {$touched} shift(s) procesado(s).");

        return self::SUCCESS;
    }
}
