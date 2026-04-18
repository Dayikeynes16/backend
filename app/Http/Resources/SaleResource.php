<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'status' => $this->status instanceof \App\Enums\SaleStatus ? $this->status->value : $this->status,
            'payment_method' => $this->payment_method,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'amount_pending' => (float) $this->amount_pending,
            'origin' => $this->origin,
            'origin_name' => $this->origin_name,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'cancel_requested_at' => $this->cancel_requested_at,
            'cancel_request_reason' => $this->cancel_request_reason,
            'created_at' => $this->created_at->toIso8601String(),
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'delivery_type' => $this->delivery_type,
            'delivery_address' => $this->delivery_address,
            'delivery_distance_km' => $this->delivery_distance_km !== null ? (float) $this->delivery_distance_km : null,
            'delivery_fee' => $this->delivery_fee !== null ? (float) $this->delivery_fee : null,
            'cart_note' => $this->cart_note,
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'user_id' => $p->user_id,
                'updated_by' => $p->updated_by,
                'created_at' => $p->created_at->toIso8601String(),
            ])),
        ];
    }
}
