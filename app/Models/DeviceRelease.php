<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Última versión publicada por tipo de equipo, leída de los feeds del bucket. */
#[Fillable(['kind', 'version', 'known_since', 'checked_at'])]
class DeviceRelease extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'kind';

    protected $keyType = 'string';

    // Con microsegundos: sin esto, Eloquent guarda "Y-m-d H:i:s" y una
    // comparación exacta de `known_since` contra el Carbon original en memoria
    // (p. ej. en tests) nunca es igual tras el roundtrip a la base.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'known_since' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
