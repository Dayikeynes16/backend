<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'branch_id', 'customer_id', 'user_id',
    'folio', 'method',
    'amount_received', 'amount_applied', 'change_given',
    'sales_affected_count', 'notes',
    'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class CustomerPayment extends Model
{
    use BelongsToTenant, SoftDeletes;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected function casts(): array
    {
        return [
            'amount_received' => 'decimal:2',
            'amount_applied' => 'decimal:2',
            'change_given' => 'decimal:2',
            'sales_affected_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }
}
