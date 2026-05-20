<?php

namespace App\Models;

use App\Enums\PurchaseProductCategory;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'name', 'unit', 'category', 'status', 'created_by',
])]
class PurchaseProduct extends Model
{
    use BelongsToTenant, SoftDeletes;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    protected function casts(): array
    {
        return [
            'category' => PurchaseProductCategory::class,
        ];
    }
}
