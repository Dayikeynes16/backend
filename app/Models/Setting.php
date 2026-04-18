<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'key', 'value'])]
class Setting extends Model
{
    public static function get(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        $row = static::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        return $row?->value ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $tenantId = null): void
    {
        static::updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => is_scalar($value) || is_null($value) ? $value : json_encode($value)],
        );
    }

    public static function setIfMissing(string $key, mixed $value, ?int $tenantId = null): void
    {
        $exists = static::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->exists();

        if (! $exists) {
            static::set($key, $value, $tenantId);
        }
    }
}
