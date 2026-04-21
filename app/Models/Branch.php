<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id', 'name', 'address', 'latitude', 'longitude', 'phone', 'schedule',
    'payment_methods_enabled', 'ticket_config', 'status',
    'online_ordering_enabled', 'delivery_enabled', 'pickup_enabled',
    'delivery_tiers', 'max_delivery_km', 'min_order_amount',
    'public_phone', 'hours',
])]
class Branch extends Model
{
    use BelongsToTenant;

    /**
     * Métodos de pago soportados por el sistema (alineados con el schema
     * de cash_register_shifts.declared_* y payments.method).
     */
    public const SUPPORTED_PAYMENT_METHODS = ['cash', 'card', 'transfer'];

    protected function casts(): array
    {
        return [
            'payment_methods_enabled' => 'array',
            'ticket_config' => 'array',
            'delivery_tiers' => 'array',
            'hours' => 'array',
            'online_ordering_enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'pickup_enabled' => 'boolean',
            'max_delivery_km' => 'decimal:3',
            'min_order_amount' => 'decimal:2',
        ];
    }

    /**
     * Devuelve los métodos de pago habilitados para esta sucursal.
     * Si la configuración es NULL/vacía se asume que todos están activos (compat).
     * La intersección con los soportados protege contra slugs desconocidos.
     *
     * @return array<int,string>
     */
    public function enabledPaymentMethods(): array
    {
        $methods = $this->payment_methods_enabled;

        if (! is_array($methods) || empty($methods)) {
            return self::SUPPORTED_PAYMENT_METHODS;
        }

        return array_values(array_intersect(self::SUPPORTED_PAYMENT_METHODS, $methods));
    }

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
