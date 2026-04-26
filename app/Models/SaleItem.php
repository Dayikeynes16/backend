<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id', 'product_id', 'presentation_id',
    'product_name', 'unit_type', 'quantity',
    'unit_price', 'original_unit_price', 'cost_price_at_sale', 'subtotal', 'notes',
    // Presentation tracking (phase 1)
    'presentation_snapshot', 'sale_mode_at_sale', 'quantity_unit',
])]
class SaleItem extends Model
{
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
