<?php

namespace App\Support;

use App\Models\Sale;

/**
 * Fuente única del total de una venta.
 *
 * El total NO es solo la suma de las líneas: las ventas a domicilio llevan
 * además el costo de envío. `OrderLinkService` siempre lo calculó bien, pero
 * `AssignCustomerToSale` y `SaleItemEditor` sumaban únicamente las líneas, así
 * que asignar un cliente o tocar una línea borraba el envío del total —
 * silenciosamente y con dinero de por medio.
 *
 * Cualquier código que recalcule `sales.total` debe pasar por aquí.
 */
class SaleTotals
{
    /**
     * Total de la venta: líneas vivas + costo de envío.
     */
    public static function forSale(Sale $sale): float
    {
        return round(self::linesSubtotal($sale) + self::deliveryFee($sale), 2);
    }

    /**
     * Suma de las líneas vivas, sin el envío.
     */
    public static function linesSubtotal(Sale $sale): float
    {
        return round((float) $sale->items()->sum('subtotal'), 2);
    }

    public static function deliveryFee(Sale $sale): float
    {
        return (float) ($sale->delivery_fee ?? 0);
    }
}
