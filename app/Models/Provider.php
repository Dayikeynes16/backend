<?php

namespace App\Models;

use App\Enums\ProviderType;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'name', 'phone', 'email', 'rfc', 'address',
    'type', 'notes', 'status', 'created_by',
])]
class Provider extends Model
{
    use BelongsToTenant, SoftDeletes;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProviderPayment::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
        ];
    }
}
