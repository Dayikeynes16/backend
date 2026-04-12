<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'branch_id', 'customer_id', 'user_id', 'folio', 'payment_method', 'total', 'amount_paid', 'amount_pending', 'origin', 'origin_name', 'status', 'completed_at', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'cancel_requested_at', 'cancel_requested_by', 'cancel_request_reason', 'locked_by', 'locked_at'])]
class Sale extends Model
{
    use BelongsToTenant, SoftDeletes;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function cancelRequestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancel_requested_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLockedBy(?int $userId): bool
    {
        if (! $this->locked_by) return false;
        if ($this->locked_by === $userId) return true;
        // Lock expires after 5 minutes
        if ($this->locked_at && $this->locked_at->diffInMinutes(now()) >= 5) return false;
        return true;
    }

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_pending' => 'decimal:2',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }
}
