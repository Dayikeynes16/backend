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

    protected function casts(): array
    {
        return [
            'known_since' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
