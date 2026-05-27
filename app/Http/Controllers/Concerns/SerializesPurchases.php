<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Purchase;
use App\Models\PurchaseItem;

/**
 * Serialización canónica de una compra (con items, pagos, adjuntos e historial)
 * para el frontend. Compartida entre el listado de Compras (HandlesPurchases) y
 * el detalle de proveedor (HandlesProviderDetail) para evitar divergencias.
 *
 * El estado de pago se deriva del modelo (Purchase::paymentStatus()), única
 * fuente de verdad.
 */
trait SerializesPurchases
{
    /**
     * @return array<string, mixed>
     */
    protected function serializePurchase(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'folio' => $p->folio,
            'invoice_number' => $p->invoice_number,
            'purchased_at' => $p->purchased_at?->toIso8601String(),
            'status' => $p->status->value,
            'provider' => $p->provider ? [
                'id' => $p->provider->id,
                'name' => $p->provider->name,
            ] : null,
            'branch' => $p->branch ? [
                'id' => $p->branch->id,
                'name' => $p->branch->name,
            ] : null,
            'subtotal' => (float) $p->subtotal,
            'total' => (float) $p->total,
            'amount_paid' => (float) $p->amount_paid,
            'amount_pending' => (float) $p->amount_pending,
            'payment_status' => $p->paymentStatus(),
            'notes' => $p->notes,
            'items' => $p->items->map(fn (PurchaseItem $i) => [
                'id' => $i->id,
                'purchase_product_id' => $i->purchase_product_id,
                'concept' => $i->concept,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit,
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
                'notes' => $i->notes,
            ])->values(),
            'payments' => $p->payments->map(fn ($pay) => [
                'id' => $pay->id,
                'paid_at' => $pay->paid_at?->toIso8601String(),
                'amount' => (float) $pay->amount,
                'payment_method' => $pay->payment_method?->value,
                'reference' => $pay->reference,
                'notes' => $pay->notes,
                'cancelled_at' => $pay->cancelled_at?->toIso8601String(),
                'cancel_reason' => $pay->cancel_reason,
            ])->values(),
            'attachments' => $p->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])->values(),
            'cancelled_at' => $p->cancelled_at?->toIso8601String(),
            'cancel_reason' => $p->cancel_reason,
            'history' => $p->relationLoaded('history')
                ? $p->history->take(50)->map(fn ($h) => [
                    'event' => $h->event->value,
                    'user_name' => $h->user?->name ?? 'Usuario eliminado',
                    'created_at' => $h->created_at?->toIso8601String(),
                    'changes' => $h->changes,
                ])->values()
                : [],
        ];
    }
}
