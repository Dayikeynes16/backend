<?php

namespace App\Http\Resources\Hub;

use App\Enums\SaleStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

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
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            // Vínculo con pedidos web (pastillas 🔗/Cumple en el historial), misma
            // forma que SaleResource. Solo si la relación fue eager-loaded.
            'linked_order_id' => $this->linked_order_id,
            'linked_order' => $this->whenLoaded('linkedOrder', fn () => $this->linkedOrder ? [
                'id' => $this->linkedOrder->id,
                'folio' => $this->linkedOrder->folio,
            ] : null),
            'fulfilled_by' => $this->whenLoaded('fulfilledBy', fn () => $this->fulfilledBy ? [
                'id' => $this->fulfilledBy->id,
                'folio' => $this->fulfilledBy->folio,
            ] : null),
            // cancel_requested_at no está casteado a datetime en el modelo Sale;
            // se parsea defensivamente para no alterar el modelo compartido.
            'cancel_requested_at' => $this->cancel_requested_at
                ? Carbon::parse($this->cancel_requested_at)->toIso8601String()
                : null,
            'cancel_request_reason' => $this->cancel_request_reason,
            // Bloqueo de concurrencia: en uso por otro usuario si está bloqueada
            // por alguien distinto y el lock no expiró (< 5 min).
            'locked_by_other' => $this->isLockedByOther($request),
            'locked_by_name' => $this->whenLoaded('lockedByUser', fn () => $this->lockedByUser?->name),
            // Cliente asignado (precios preferenciales). Solo se incluye si la
            // relación fue eager-loaded; el catálogo lo expone en el detalle.
            // Teléfono de WhatsApp asociado a la venta (chip persistente del
            // hub; puede venir del cliente o capturado manual).
            'contact_phone' => $this->contact_phone,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product_name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
                'notes' => $i->notes,
                // Campos de presentación/unidad para formatear cantidades por peso
                // (1.250 kg) o presentación ("Queso (500 g) × 2") en el hub.
                'unit_type' => $i->unit_type,
                'quantity_unit' => $i->quantity_unit,
                'sale_mode_at_sale' => $i->sale_mode_at_sale,
                'presentation_snapshot' => $i->presentation_snapshot,
                // Badge "Editado" por renglón (misma regla que la web:
                // updated_by presente y updated_at ≠ created_at).
                'updated_by' => $i->updated_by,
                'created_at' => $i->created_at?->toIso8601String(),
                'updated_at' => $i->updated_at?->toIso8601String(),
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'created_at' => $p->created_at->toIso8601String(),
                // Un pago hijo de un cobro global NO es editable individualmente
                // (misma regla que la web); el hub oculta Editar/Eliminar con esto.
                'customer_payment_id' => $p->customer_payment_id,
                // Quién cobró y quién corrigió (badge "Editado"), como la web.
                'user' => $p->relationLoaded('user') && $p->user
                    ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
                'updated_by_user' => $p->relationLoaded('updatedByUser') && $p->updatedByUser
                    ? ['id' => $p->updatedByUser->id, 'name' => $p->updatedByUser->name] : null,
            ])),
        ];
    }

    private function isLockedByOther(Request $request): bool
    {
        $userId = $request->user()?->id;

        return (bool) (
            $this->locked_by
            && $this->locked_by !== $userId
            && $this->locked_at
            && Carbon::parse($this->locked_at)->diffInMinutes(now()) < 5
        );
    }
}
