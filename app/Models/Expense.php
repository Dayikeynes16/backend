<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'branch_id', 'expense_subcategory_id', 'user_id', 'updated_by',
    'cancelled_by', 'concept', 'amount', 'expense_at', 'description',
    'cancellation_reason',
])]
class Expense extends Model
{
    use BelongsToTenant, SoftDeletes;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class, 'expense_subcategory_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_at' => 'datetime',
        ];
    }
}
