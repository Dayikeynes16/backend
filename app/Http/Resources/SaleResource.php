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
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'total' => (float) $this->total,
            'origin' => $this->origin,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
