<?php

namespace App\Http\Resources\Hub;

use App\Enums\PurchaseStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HubPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof PurchaseStatus ? $this->status : PurchaseStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => $status?->value ?? $this->status,
            'payment_status' => $this->paymentStatus($status),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider
                ? ['id' => $this->provider->id, 'name' => $this->provider->name]
                : null),
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'amount_pending' => (float) $this->amount_pending,
            'invoice_number' => $this->invoice_number,
            'notes' => $this->notes,
            'purchased_at' => $this->purchased_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'purchase_product_id' => $i->purchase_product_id,
                'concept' => $i->concept,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit,
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
                'notes' => $i->notes,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method instanceof \BackedEnum ? $p->payment_method->value : $p->payment_method,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'cancelled_at' => $p->cancelled_at?->toIso8601String(),
            ])),
        ];
    }

    private function paymentStatus(?PurchaseStatus $status): string
    {
        if ($status === PurchaseStatus::Cancelled) {
            return 'cancelled';
        }
        $paid = (float) $this->amount_paid;
        $total = (float) $this->total;
        if ($paid >= $total && $total > 0) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'pending';
    }
}
