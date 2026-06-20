<?php

namespace App\Http\Resources\Hub;

use App\Enums\SaleStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HubSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof SaleStatus ? $this->status : SaleStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => $status?->value ?? $this->status,
            'status_label' => $status?->label(),
            'payment_method' => $this->payment_method,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'amount_pending' => (float) $this->amount_pending,
            'origin' => $this->origin,
            'origin_name' => $this->origin_name,
            'created_at' => $this->created_at->toIso8601String(),
            // Cliente asignado (precios preferenciales). Solo se incluye si la
            // relación fue eager-loaded; el catálogo lo expone en el detalle.
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_name' => $i->product_name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
                // Campos de presentación/unidad para formatear cantidades por peso
                // (1.250 kg) o presentación ("Queso (500 g) × 2") en el hub.
                'unit_type' => $i->unit_type,
                'quantity_unit' => $i->quantity_unit,
                'sale_mode_at_sale' => $i->sale_mode_at_sale,
                'presentation_snapshot' => $i->presentation_snapshot,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'created_at' => $p->created_at->toIso8601String(),
            ])),
        ];
    }
}
