<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'branch_id', 'user_id',
    'opened_at', 'closed_at',
    'total_cash', 'total_card', 'total_transfer', 'total_sales', 'sale_count',
])]
class CashRegisterShift extends Model
{
    use BelongsToTenant;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'total_cash' => 'decimal:2',
            'total_card' => 'decimal:2',
            'total_transfer' => 'decimal:2',
            'total_sales' => 'decimal:2',
        ];
    }
}
