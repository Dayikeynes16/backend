<?php

namespace App\Models;

use App\Services\PhoneNormalizer;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'branch_id', 'name', 'name_pending', 'phone', 'notes', 'status'])]
class Customer extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'name_pending' => 'boolean',
        ];
    }

    /**
     * El teléfono se guarda siempre en E.164. Al vivir en el modelo, cubre a
     * todos los canales que dan de alta clientes (CRUD web, hub, asistente IA,
     * pedido web y captura desde la venta) sin que ninguno tenga que acordarse.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNormalizer::normalize($value));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CustomerProductPrice::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
