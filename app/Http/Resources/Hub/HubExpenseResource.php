<?php

namespace App\Http\Resources\Hub;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HubExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'concept' => $this->concept,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'expense_at' => $this->expense_at?->toIso8601String(),
            'description' => $this->description,
            'subcategory' => $this->whenLoaded('subcategory', fn () => $this->subcategory ? [
                'id' => $this->subcategory->id,
                'name' => $this->subcategory->name,
                'category' => $this->subcategory->relationLoaded('category') && $this->subcategory->category
                    ? ['id' => $this->subcategory->category->id, 'name' => $this->subcategory->category->name]
                    : null,
            ] : null),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])->values()),
        ];
    }
}
