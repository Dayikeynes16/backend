<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_id', 'product_id', 'concept', 'quantity', 'unit',
    'unit_price', 'subtotal', 'notes',
])]
class PurchaseItem extends Model
{
    // Sin BelongsToTenant: vive bajo Purchase, se accede vía la relación.
    // Mismo patrón que SaleItem.

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'subtotal' => 'decimal:2',
        ];
    }
}
