<?php

namespace App\Services\Customers;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\SaleItemMath;

/**
 * Calcula qué pasaría si se asignara un cliente a una venta, sin escribir nada.
 *
 * Existe porque asignar cliente NO es una operación neutra: `AssignCustomerToSale`
 * recalcula los precios de línea con los preferenciales del cliente y puede
 * transicionar la venta a `Completed`. Capturar un teléfono no debe alterar en
 * silencio el total de una venta ya cobrada — pero tampoco tiene sentido pedir
 * confirmación cuando no cambia nada, que es el caso más común.
 *
 * Debe mantenerse en sintonía con la lógica de `AssignCustomerToSale::execute()`.
 */
class CustomerAssignmentPreview
{
    /**
     * @return array{
     *     current_total: float,
     *     new_total: float,
     *     changes_total: bool,
     *     would_complete: bool,
     *     skipped_piece_presentations: array<int, string>
     * }
     */
    public static function for(Sale $sale, Customer $customer): array
    {
        $sale->loadMissing('items');
        $customer->loadMissing('prices');

        $preferentialPrices = $customer->prices->keyBy('product_id');
        $skipped = [];
        $newTotal = 0.0;

        foreach ($sale->items as $item) {
            $prefPrice = $preferentialPrices->get($item->product_id);

            if (! $prefPrice) {
                $newTotal += (float) $item->subtotal;

                continue;
            }

            // Presentaciones por pieza sin equivalencia en kg/l: el precio
            // preferencial ($/kg) no se puede aplicar, la línea queda igual.
            if (! SaleItemMath::isWeightOrVolume($item)) {
                $skipped[] = $item->product_name;
                $newTotal += (float) $item->subtotal;

                continue;
            }

            $unitPrice = SaleItemMath::unitPriceForBasePrice($item, (float) $prefPrice->price);
            $newTotal += round($unitPrice * (float) $item->quantity, 2);
        }

        $newTotal = round($newTotal, 2);
        $currentTotal = round((float) $sale->total, 2);
        $amountPaid = (float) $sale->amount_paid;
        $newPending = round(max($newTotal - $amountPaid, 0), 2);

        return [
            'current_total' => $currentTotal,
            'new_total' => $newTotal,
            'changes_total' => abs($newTotal - $currentTotal) >= 0.01,
            'would_complete' => $newPending <= 0 && $amountPaid > 0 && $sale->status !== SaleStatus::Completed,
            'skipped_piece_presentations' => array_values(array_unique($skipped)),
        ];
    }
}
