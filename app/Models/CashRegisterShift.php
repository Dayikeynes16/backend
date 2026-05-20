<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'branch_id', 'user_id',
    'opened_at', 'opening_amount', 'closed_at',
    'total_cash', 'total_card', 'total_transfer', 'total_cash_expenses', 'total_cash_provider_payments', 'total_sales', 'sale_count',
    'sales_generated_amount', 'sales_generated_count',
    'collections_from_today_amount', 'collections_from_previous_amount',
    'declared_amount', 'declared_card', 'declared_transfer',
    'expected_amount', 'difference', 'difference_card', 'difference_transfer',
    'notes',
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

    public function withdrawals(): HasMany
    {
        return $this->hasMany(CashWithdrawal::class, 'shift_id');
    }

    public function cashExpenses(): HasMany
    {
        return $this->hasMany(Expense::class)->where('payment_method', 'cash');
    }

    public function cashProviderPayments(): HasMany
    {
        return $this->hasMany(ProviderPayment::class)
            ->where('payment_method', 'cash')
            ->whereNull('cancelled_at');
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_amount' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_card' => 'decimal:2',
            'total_transfer' => 'decimal:2',
            'total_cash_expenses' => 'decimal:2',
            'total_cash_provider_payments' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'sales_generated_amount' => 'decimal:2',
            'collections_from_today_amount' => 'decimal:2',
            'collections_from_previous_amount' => 'decimal:2',
            'declared_amount' => 'decimal:2',
            'declared_card' => 'decimal:2',
            'declared_transfer' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'difference_card' => 'decimal:2',
            'difference_transfer' => 'decimal:2',
        ];
    }
}
