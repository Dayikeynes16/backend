<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'slug', 'rfc', 'logo_path', 'address', 'phone', 'owner_whatsapp', 'max_branches', 'max_users', 'ai_monthly_budget_cents', 'status'])]
class Tenant extends Model
{
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->logo_path) {
                return null;
            }
            try {
                return Storage::disk('public')->url($this->logo_path);
            } catch (\Throwable) {
                return '/storage/'.ltrim($this->logo_path, '/');
            }
        });
    }
}
