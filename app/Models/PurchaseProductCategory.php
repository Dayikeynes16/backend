<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de categorías de productos de compra (tenant-wide, administrable).
 * Reemplaza al enum fijo anterior: ahora cada empresa crea/edita las suyas.
 */
#[Fillable(['tenant_id', 'name', 'status', 'created_by'])]
class PurchaseProductCategory extends Model
{
    use BelongsToTenant;

    /**
     * Categorías estándar sembradas por tenant como punto de partida. Después
     * son completamente editables por el usuario.
     *
     * @var array<int, string>
     */
    public const DEFAULTS = ['Res', 'Cerdo', 'Pollo', 'Insumos', 'Otro'];

    public function products(): HasMany
    {
        return $this->hasMany(PurchaseProduct::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Siembra las categorías estándar para un tenant (idempotente). Ignora el
     * scope de tenant porque se invoca al crear el tenant, sin contexto bound.
     */
    public static function seedDefaultsFor(int $tenantId): void
    {
        foreach (self::DEFAULTS as $name) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $name],
                ['status' => 'active'],
            );
        }
    }
}
