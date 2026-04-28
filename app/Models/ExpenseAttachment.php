<?php

namespace App\Models;

use App\Services\ExpenseAttachmentService;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'expense_id', 'tenant_id', 'uploaded_by', 'original_name', 'path',
    'mime_type', 'size_bytes',
])]
class ExpenseAttachment extends Model
{
    use BelongsToTenant;

    /**
     * Hook deleting: borrar el archivo físico cuando se elimina el adjunto.
     * El soft-delete del gasto NO dispara esto (no eliminamos el adjunto al
     * cancelar un gasto — se conserva como evidencia).
     */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment) {
            $disk = ExpenseAttachmentService::disk();
            if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
                Storage::disk($disk)->delete($attachment->path);
            }
        });
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }
}
