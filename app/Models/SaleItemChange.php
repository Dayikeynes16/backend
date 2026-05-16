<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id', 'sale_item_id', 'event',
    'before', 'after', 'diff',
    'reason', 'user_id',
])]
class SaleItemChange extends Model
{
    public const EVENT_ADDED = 'added';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_REMOVED = 'removed';

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'diff' => 'array',
        ];
    }
}
