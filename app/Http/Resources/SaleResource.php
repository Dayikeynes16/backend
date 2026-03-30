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
            'status' => $this->status instanceof \App\Enums\SaleStatus ? $this->status->value : $this->status,
            'payment_method' => $this->payment_method,
            'total' => (float) $this->total,
            'origin' => $this->origin,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'cancel_requested_at' => $this->cancel_requested_at,
            'created_at' => $this->created_at->toIso8601String(),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
