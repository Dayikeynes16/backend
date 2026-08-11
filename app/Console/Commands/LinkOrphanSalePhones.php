<?php

namespace App\Console\Commands;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\Customers\ResolveCustomerByPhone;
use App\Services\PhoneNormalizer;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Recupera los teléfonos que quedaron guardados en `sales.contact_phone` sin
 * cliente asociado, de cuando capturar un teléfono no creaba cliente.
 *
 * NO usa `AssignCustomerToSale`: ese servicio recalcula precios preferenciales
 * y podría alterar el total de ventas históricas ya cobradas. Aquí solo se
 * rellena `customer_id`, dejando intactos importes y estado.
 */
class LinkOrphanSalePhones extends Command
{
    protected $signature = 'sales:link-orphan-phones
                            {--dry-run=true : Reporta sin modificar datos}
                            {--branch= : Limitar a una sucursal}';

    protected $description = 'Asocia a un cliente las ventas con contact_phone huerfano';

    public function handle(ResolveCustomerByPhone $resolver): int
    {
        $dryRun = filter_var($this->option('dry-run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $this->info($dryRun ? '=== DRY RUN — no se escribe nada ===' : '=== APLICANDO CAMBIOS ===');

        $sales = Sale::withoutGlobalScopes()
            ->whereNull('customer_id')
            ->whereNotNull('contact_phone')
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->when($this->option('branch'), fn ($q, $b) => $q->where('branch_id', (int) $b))
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'branch_id', 'contact_phone', 'folio']);

        if ($sales->isEmpty()) {
            $this->info('No hay ventas con teléfono huérfano.');

            return self::SUCCESS;
        }

        $uniquePhones = $sales
            ->map(fn ($s) => PhoneNormalizer::normalize($s->contact_phone))
            ->filter(fn ($p) => PhoneNormalizer::isPlausible($p))
            ->unique()
            ->count();

        $this->line("{$sales->count()} venta(s) con teléfono huérfano, {$uniquePhones} número(s) distinto(s).");
        $this->newLine();

        $linked = 0;
        $created = 0;
        $skipped = 0;

        foreach ($sales as $sale) {
            $normalized = PhoneNormalizer::normalize($sale->contact_phone);

            if (! PhoneNormalizer::isPlausible($normalized)) {
                $this->warn("  {$sale->folio}: '{$sale->contact_phone}' no parece un teléfono — se omite.");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  {$sale->folio} → {$normalized}");
                $linked++;

                continue;
            }

            try {
                $resolution = $resolver->execute($normalized, $sale->branch_id, $sale->tenant_id);

                // Solo se rellena el cliente: importes y estado quedan intactos.
                Sale::withoutGlobalScopes()
                    ->where('id', $sale->id)
                    ->update(['customer_id' => $resolution->customer->id]);

                $linked++;
                $created += $resolution->wasCreated ? 1 : 0;
            } catch (InvalidArgumentException $e) {
                $this->warn("  {$sale->folio}: {$e->getMessage()} — se omite.");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run: {$linked} venta(s) se asociarian, {$skipped} se omitirian. Re-ejecuta con --dry-run=false."
            : "Listo. {$linked} venta(s) asociadas, {$created} cliente(s) creados, {$skipped} omitidas.");

        return self::SUCCESS;
    }
}
