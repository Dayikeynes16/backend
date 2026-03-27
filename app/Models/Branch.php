<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'name', 'address', 'latitude', 'longitude', 'phone', 'schedule', 'payment_methods_enabled', 'status'])]
class Branch extends Model
{
    use BelongsToTenant;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function apiKey(): HasOne
    {
        return $this->hasOne(ApiKey::class)->where('status', 'active');
    }

    public function cashRegisterShifts(): HasMany
    {
        return $this->hasMany(CashRegisterShift::class);
    }
}
