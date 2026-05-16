<?php

namespace App\Support;

use App\Models\ProductPresentation;
use App\Models\SaleItem;

/**
 * Helpers para congelar el estado de presentaciones y de items completos.
 * Usados por:
 *  - WorkbenchController al crear la venta inicial
 *  - SaleItemEditor al registrar before/after en sale_item_changes
 */
final class SaleItemSnapshot
{
    /**
     * @return array{id: int, name: string, content: float, unit: string, price: float}
     */
    public static function presentation(ProductPresentation $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'content' => (float) $p->content,
            'unit' => $p->unit,
            'price' => (float) $p->price,
        ];
    }

    /**
     * Snapshot autocontenido del item para el log de auditoría. No incluye
     * `cost_price_at_sale` ni `original_unit_price` (datos internos), pero sí
     * todo lo necesario para reconstruir el cobro al cliente.
     *
     * @return array{
     *   id: int, product_id: ?int, product_name: string, presentation_id: ?int,
     *   unit_type: string, quantity: float, unit_price: float, subtotal: float,
     *   notes: ?string
     * }
     */
    public static function item(SaleItem $item): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product_name,
            'presentation_id' => $item->presentation_id,
            'unit_type' => $item->unit_type,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
            'notes' => $item->notes,
        ];
    }
}
