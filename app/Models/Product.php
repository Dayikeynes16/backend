<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['tenant_id', 'branch_id', 'category_id', 'name', 'description', 'image_path', 'price', 'cost_price', 'unit_type', 'sale_mode', 'status', 'visibility', 'visible_online'])]
class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $appends = ['image_url'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(ProductPresentation::class)->orderBy('sort_order');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->image_path) {
                return null;
            }

            try {
                return Storage::url($this->image_path);
            } catch (\Throwable) {
                return '/storage/'.$this->image_path;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'visible_online' => 'boolean',
        ];
    }
}
