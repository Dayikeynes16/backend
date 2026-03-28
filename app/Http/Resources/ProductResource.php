<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'unit_type' => $this->unit_type,
            'sale_mode' => $this->sale_mode,
            'price' => (float) $this->price,
            'image_url' => $this->image_url,
            'status' => $this->status,
            'presentations' => ProductPresentationResource::collection($this->whenLoaded('presentations')),
        ];
    }
}
