<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait RecordsHistory
{
    /**
     * Historial de cambios del modelo, más reciente primero.
     */
    public function history(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
