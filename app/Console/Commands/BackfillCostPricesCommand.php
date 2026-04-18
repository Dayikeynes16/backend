<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCostPricesCommand extends Command
{
    protected $signature = 'metrics:backfill-cost-prices';

    protected $description = 'Rellena cost_price_at_sale en sale_items NULL usando el costo actual de cada producto';

    public function handle(): int
    {
        $filled = 0;
        $missing = 0;

        SaleItem::query()
            ->whereNull('cost_price_at_sale')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->chunkById(1000, function ($items) use (&$filled, &$missing) {
                $productIds = $items->pluck('product_id')->unique()->values();
                $costs = Product::withoutGlobalScopes()
                    ->whereIn('id', $productIds)
                    ->pluck('cost_price', 'id');

                foreach ($items as $item) {
                    $cost = $costs[$item->product_id] ?? null;
                    if ($cost === null) {
                        $missing++;

                        continue;
                    }
                    DB::table('sale_items')
                        ->where('id', $item->id)
                        ->update(['cost_price_at_sale' => $cost]);
                    $filled++;
                }
            });

        Setting::setIfMissing('metrics.backfill_run_at', now()->toDateTimeString());

        $this->info('Backfill completado.');
        $this->line("  Items rellenados: {$filled}");
        $this->line("  Items sin costo disponible (NULL): {$missing}");
        $this->line('  Fecha registrada en settings: '.Setting::get('metrics.backfill_run_at'));

        return self::SUCCESS;
    }
}
