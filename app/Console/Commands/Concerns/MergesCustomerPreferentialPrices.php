<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reasignación segura de precios preferenciales al fusionar clientes.
 *
 * `customer_product_prices` tiene UNIQUE(customer_id, product_id), así que el
 * `UPDATE ... SET customer_id = keep` masivo revienta con violación de unicidad
 * en cuanto el superviviente y el duplicado tienen precio del mismo producto.
 *
 * En ese choque gana el precio del superviviente y el del duplicado se descarta
 * — arbitrario pero determinista, y se avisa por consola para que alguien lo
 * revise a mano.
 *
 * Compartido por `customers:dedup` y `customers:normalize-phones`.
 */
trait MergesCustomerPreferentialPrices
{
    /**
     * @param  array<int, int>|Collection<int, int>  $duplicateIds
     * @return int Número de filas reasignadas al superviviente
     */
    protected function mergePreferentialPrices(int $keepId, $duplicateIds): int
    {
        $keepProductIds = DB::table('customer_product_prices')
            ->where('customer_id', $keepId)
            ->pluck('product_id')
            ->all();

        if ($keepProductIds !== []) {
            $conflicting = DB::table('customer_product_prices')
                ->whereIn('customer_id', $duplicateIds)
                ->whereIn('product_id', $keepProductIds)
                ->count();

            if ($conflicting > 0) {
                $this->warn("  #{$keepId}: {$conflicting} precio(s) preferencial(es) del duplicado se descartan — el superviviente ya tenía precio de ese producto.");

                DB::table('customer_product_prices')
                    ->whereIn('customer_id', $duplicateIds)
                    ->whereIn('product_id', $keepProductIds)
                    ->delete();
            }
        }

        return DB::table('customer_product_prices')
            ->whereIn('customer_id', $duplicateIds)
            ->update(['customer_id' => $keepId]);
    }
}
