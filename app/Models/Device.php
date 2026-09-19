<?php

namespace App\Models;

use App\Services\Devices\BatteryThresholds;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un equipo de sucursal (báscula o hub) que se presenta a la nube con su
 * `device_id`. La identidad es ese identificador, no el nombre ni la API key:
 * el nombre lo cambia el administrador desde la web (`display_name`) y la API
 * key es de la sucursal, no del aparato.
 */
#[Fillable([
    'tenant_id', 'branch_id', 'device_id', 'kind', 'name', 'display_name',
    'app_version', 'os', 'model', 'battery_level', 'battery_charging',
    'connection', 'local_ip', 'via', 'last_seen_at', 'first_seen_at',
    'muted_at', 'retired_at', 'battery_alert_level', 'silent_alerted_at',
    'outdated_alert_version',
])]
class Device extends Model
{
    use BelongsToTenant;

    public const KINDS = ['scale_android', 'scale_windows', 'hub_windows', 'hub_android'];

    public const KIND_LABELS = [
        'scale_android' => 'Báscula Android',
        'scale_windows' => 'Báscula Surface',
        'hub_windows' => 'Hub Windows',
        'hub_android' => 'Hub Android',
    ];

    protected function casts(): array
    {
        return [
            'battery_charging' => 'boolean',
            'last_seen_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'muted_at' => 'datetime',
            'retired_at' => 'datetime',
            'silent_alerted_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function isMuted(): bool
    {
        return $this->muted_at !== null;
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function displayName(): string
    {
        return $this->display_name ?: $this->name;
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    /**
     * Estado derivado de cuándo reportó y de su batería. `retired` gana a todo.
     * Las dos alertas de batería solo cuentan si está en línea: una lectura
     * vieja de un equipo callado no dice nada del presente.
     */
    public function statusFor(BatteryThresholds $thresholds): string
    {
        if ($this->retired_at !== null) {
            return 'retired';
        }

        $seen = $this->last_seen_at instanceof Carbon ? $this->last_seen_at : Carbon::parse($this->last_seen_at);
        $minutes = $seen->diffInMinutes(Carbon::now());

        if ($minutes >= config('devices.silence_minutes', 30)) {
            return 'silent';
        }
        if ($minutes >= config('devices.online_minutes', 10)) {
            return 'stale';
        }

        return match ($thresholds->severityFor($this->battery_level, (bool) $this->battery_charging)) {
            'critical' => 'battery_critical',
            'warn' => 'battery_low',
            default => 'online',
        };
    }

    /**
     * Atajo para todo lo que ya escribía `$device->status`.
     *
     * Sin guardia: Eloquent no consulta una relación `belongsTo` cuya clave
     * foránea es nula, así que un `new Device([...])` sin `branch_id` (como
     * en las pruebas unitarias) no dispara ninguna consulta. Con `branch_id`
     * puesto sí la dispararía si nadie precargó la relación -por eso las
     * consultas que presentan muchos equipos (`BranchDevicesQuery`,
     * `BranchDeviceAlertsQuery`, el comando `devices:check`) cargan `branch`
     * explícitamente con `with('branch')` y evitan un N+1.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): string => $this->statusFor(BatteryThresholds::fromBranch($this->branch)));
    }
}
