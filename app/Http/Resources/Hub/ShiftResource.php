<?php

namespace App\Http\Resources\Hub;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'opening_amount' => (float) $this->opening_amount,
            'closed' => $this->closed_at !== null,
            'total_cash' => $this->total_cash !== null ? (float) $this->total_cash : null,
            'total_card' => $this->total_card !== null ? (float) $this->total_card : null,
            'total_transfer' => $this->total_transfer !== null ? (float) $this->total_transfer : null,
            'sale_count' => $this->sale_count !== null ? (int) $this->sale_count : null,
            'declared_amount' => $this->declared_amount !== null ? (float) $this->declared_amount : null,
            'declared_card' => $this->declared_card !== null ? (float) $this->declared_card : null,
            'declared_transfer' => $this->declared_transfer !== null ? (float) $this->declared_transfer : null,
            'expected_amount' => $this->expected_amount !== null ? (float) $this->expected_amount : null,
            'difference' => $this->difference !== null ? (float) $this->difference : null,
            'difference_card' => $this->difference_card !== null ? (float) $this->difference_card : null,
            'difference_transfer' => $this->difference_transfer !== null ? (float) $this->difference_transfer : null,
            'notes' => $this->notes,
        ];
    }
}
