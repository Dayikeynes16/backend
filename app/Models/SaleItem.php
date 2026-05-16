<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sale_id', 'product_id', 'presentation_id',
    'product_name', 'unit_type', 'quantity',
    'unit_price', 'original_unit_price', 'cost_price_at_sale', 'subtotal', 'notes',
    // Presentation tracking (phase 1)
    'presentation_snapshot', 'sale_mode_at_sale', 'quantity_unit',
    // Auditoría
    'created_by', 'updated_by', 'deleted_by',
])]
class SaleItem extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (SaleItem $item) {
            if ($item->cost_price_at_sale === null && $item->product_id) {
                $item->cost_price_at_sale = Product::withoutGlobalScopes()
                    ->where('id', $item->product_id)
                    ->value('cost_price');
            }
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(ProductPresentation::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SaleItemChange::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'original_unit_price' => 'decimal:2',
            'cost_price_at_sale' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'presentation_snapshot' => 'array',
        ];
    }
}
