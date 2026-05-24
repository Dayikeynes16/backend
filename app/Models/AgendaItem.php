<?php

namespace App\Models;

use App\Enums\AgendaItemType;
use App\Enums\AgendaPriority;
use App\Enums\AgendaRecurrence;
use App\Enums\AgendaScope;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'type', 'title', 'body', 'scope', 'branch_id', 'user_id',
    'assigned_to_user_id', 'starts_at', 'ends_at', 'all_day', 'remind_at',
    'completed_at', 'priority', 'recurrence', 'recurrence_until',
])]
class AgendaItem extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => AgendaItemType::class,
            'scope' => AgendaScope::class,
            'priority' => AgendaPriority::class,
            'recurrence' => AgendaRecurrence::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'remind_at' => 'datetime',
            'completed_at' => 'datetime',
            'recurrence_until' => 'date',
            'all_day' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Acota la consulta a lo que el usuario puede ver:
     * company del tenant + branch de su sucursal + personales propios + asignadas.
     * admin-empresa ve todas las sucursales.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');

        return $query->where(function (Builder $q) use ($user, $isCompanyAdmin) {
            $q->where('scope', AgendaScope::Company->value)
                ->orWhere('assigned_to_user_id', $user->id)
                ->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('scope', AgendaScope::Personal->value)
                        ->where('user_id', $user->id);
                });

            if ($isCompanyAdmin) {
                $q->orWhere('scope', AgendaScope::Branch->value);
            } else {
                $q->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('scope', AgendaScope::Branch->value)
                        ->where('branch_id', $user->branch_id);
                });
            }
        });
    }
}
