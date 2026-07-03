<?php

namespace App\Services;

use App\Models\AiExpenseDraft;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Mueve los archivos de un draft de IA al directorio del gasto recién creado
     * y crea los ExpenseAttachment correspondientes. Se usa cuando el usuario
     * confirma un gasto que vino prerellenado por la IA — los archivos ya viven
     * en disco privado, no hay que re-subirlos.
     *
     * @return array<int, ExpenseAttachment>
     */
    public function attachFromDraft(Expense $expense, AiExpenseDraft $draft, ?int $uploadedBy): array
    {
        return $this->attachFromDraftPaths($expense, $draft->attachment_paths ?? [], $uploadedBy);
    }

    /**
     * Mueve archivos ya guardados en disco privado (por su metadata de path) al
     * directorio del gasto y crea los ExpenseAttachment. Comparte el mecanismo
     * entre los borradores de gasto (ai_expense_drafts) y los del asistente
     * (assistant_drafts) — ambos viven en el mismo disco privado.
     *
     * @param  array<int, array<string, mixed>>  $attachmentPaths
     * @return array<int, ExpenseAttachment>
     */
    public function attachFromDraftPaths(Expense $expense, array $attachmentPaths, ?int $uploadedBy): array
    {
        $disk = self::disk();
        $storage = Storage::disk($disk);
        $created = [];

        foreach ($attachmentPaths as $entry) {
            $srcPath = $entry['path'] ?? null;
            if (! is_string($srcPath) || ! $storage->exists($srcPath)) {
                continue;
            }

            $ext = pathinfo($srcPath, PATHINFO_EXTENSION) ?: 'bin';
            $filename = Str::uuid()->toString().'.'.$ext;
            $destPath = "tenants/{$expense->tenant_id}/expenses/{$expense->id}/{$filename}";

            // move() preserva visibility=private heredada del directorio padre.
            if (! $storage->move($srcPath, $destPath)) {
                continue;
            }

            $created[] = ExpenseAttachment::create([
                'expense_id' => $expense->id,
                'tenant_id' => $expense->tenant_id,
                'uploaded_by' => $uploadedBy,
                'original_name' => mb_substr((string) ($entry['original_name'] ?? $filename), 0, 255),
                'path' => $destPath,
                'mime_type' => (string) ($entry['mime_type'] ?? 'application/octet-stream'),
                'size_bytes' => (int) ($entry['size_bytes'] ?? 0),
            ]);
        }

        return $created;
    }
}
