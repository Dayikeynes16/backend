<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'purchase_id', 'tenant_id', 'uploaded_by',
    'original_name', 'path', 'mime_type', 'size_bytes',
])]
class PurchaseAttachment extends Model
{
    use BelongsToTenant;

    /**
     * Borra el archivo físico al borrar el modelo. Igual que ExpenseAttachment.
     */
    protected static function booted(): void
    {
        static::deleting(function (PurchaseAttachment $a) {
            if ($a->path) {
                Storage::disk(config('expenses.disk', 'local'))->delete($a->path);
            }
        });
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
