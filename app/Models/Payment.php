<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_id', 'method', 'amount'])]
class Payment extends Model
{
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
