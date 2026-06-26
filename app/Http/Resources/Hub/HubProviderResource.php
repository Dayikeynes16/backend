<?php

namespace App\Http\Resources\Hub;

use App\Enums\ProviderType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HubProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof ProviderType
            ? $this->type
            : ProviderType::tryFrom((string) $this->type);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'rfc' => $this->rfc,
            'address' => $this->address,
            'notes' => $this->notes,
            'status' => $this->status,
            'type' => $type?->value,
            'type_label' => $type?->label(),
        ];
    }
}
