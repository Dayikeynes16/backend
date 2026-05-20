<?php

namespace App\Services;

use App\Models\AiPurchaseDraft;
use App\Models\Purchase;
use App\Models\PurchaseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Espejo de ExpenseAttachmentService para el módulo de Compras. Las facturas
 * de proveedor son tan sensibles como los tickets de gasto: disco privado,
 * doble validación de MIME (controller) y descarga gated por tenant/branch.
 */
class PurchaseAttachmentService
{
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public const MAX_PER_PURCHASE = 5;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Mismo disco que gastos por defecto — la separación lógica vive en la
     * ruta `tenants/{tenant}/purchases/{purchase}/{uuid}.{ext}`.
     */
    public static function disk(): string
    {
        return config('expenses.disk', 'local');
    }

    /**
     * @param  iterable<UploadedFile>  $files
     * @return array<int, PurchaseAttachment>
     */
    public function attach(Purchase $purchase, iterable $files, ?int $uploadedBy): array
    {
        $created = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $filename = Str::uuid()->toString().'.'.$ext;
            $directory = "tenants/{$purchase->tenant_id}/purchases/{$purchase->id}";

            $stored = $file->storeAs($directory, $filename, [
                'disk' => self::disk(),
                'visibility' => 'private',
            ]);
            if (! $stored) {
                continue;
            }

            $created[] = PurchaseAttachment::create([
                'purchase_id' => $purchase->id,
                'tenant_id' => $purchase->tenant_id,
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
     * Cuando F4 (captura IA) confirme una compra, mueve los archivos del draft
     * al directorio definitivo de la compra. Stub listo para esa fase.
     *
     * @return array<int, PurchaseAttachment>
     */
    public function attachFromDraft(Purchase $purchase, AiPurchaseDraft $draft, ?int $uploadedBy): array
    {
        $disk = self::disk();
        $storage = Storage::disk($disk);
        $created = [];

        foreach ($draft->attachment_paths ?? [] as $entry) {
            $srcPath = $entry['path'] ?? null;
            if (! is_string($srcPath) || ! $storage->exists($srcPath)) {
                continue;
            }

            $ext = pathinfo($srcPath, PATHINFO_EXTENSION) ?: 'bin';
            $filename = Str::uuid()->toString().'.'.$ext;
            $destPath = "tenants/{$purchase->tenant_id}/purchases/{$purchase->id}/{$filename}";

            if (! $storage->move($srcPath, $destPath)) {
                continue;
            }

            $created[] = PurchaseAttachment::create([
                'purchase_id' => $purchase->id,
                'tenant_id' => $purchase->tenant_id,
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
