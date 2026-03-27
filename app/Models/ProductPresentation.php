<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'name', 'content', 'unit', 'price', 'sort_order', 'status'])]
class ProductPresentation extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'content' => 'decimal:3',
            'price' => 'decimal:2',
        ];
    }
}
