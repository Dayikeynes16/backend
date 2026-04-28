<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ExpenseAttachmentService
{
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public const MAX_PER_EXPENSE = 5;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Disco configurable. Por defecto 'local' (sail dev); en Laravel Cloud
     * o entornos multi-instancia configurar EXPENSES_DISK a un disco S3
     * privado para que los archivos no se pierdan entre deploys.
     */
    public static function disk(): string
    {
        return config('expenses.disk', 'local');
    }

    /**
     * Sube uno o varios archivos adjuntos para un gasto.
     * El path se construye como tenants/{tenant}/expenses/{expense}/{uuid}.{ext}
     * para mantener separación física por tenant.
     *
     * @param  iterable<UploadedFile>  $files
     * @return array<int, ExpenseAttachment>
     */
    public function attach(Expense $expense, iterable $files, ?int $uploadedBy): array
    {
        $created = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $filename = Str::uuid()->toString().'.'.$ext;
            $directory = "tenants/{$expense->tenant_id}/expenses/{$expense->id}";

            // Defensa en profundidad: forzar visibility=private aunque el disco
            // estuviera mal configurado. En S3 esto fija ACL=private en el objeto.
            $stored = $file->storeAs($directory, $filename, [
                'disk' => self::disk(),
                'visibility' => 'private',
            ]);
            if (! $stored) {
                continue;
            }

            $created[] = ExpenseAttachment::create([
                'expense_id' => $expense->id,
                'tenant_id' => $expense->tenant_id,
                'uploaded_by' => $uploadedBy,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $stored,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize() ?: 0,
            ]);
        }

        return $created;
    }
}
