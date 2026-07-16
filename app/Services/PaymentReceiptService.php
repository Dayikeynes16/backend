<?php

namespace App\Services;

use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comprobantes de pago por transferencia (venta o cobro global de fiado).
 * Espejo de ExpenseAttachmentService: disco privado, visibility=private.
 * No decide permisos ni flags — eso vive en los controladores.
 */
class PaymentReceiptService
{
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public const MAX_PER_PAYMENT = 3;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Comparte el disco privado de gastos (EXPENSES_DISK en prod). */
    public static function disk(): string
    {
        return config('expenses.disk', 'local');
    }

    /**
     * @param  iterable<UploadedFile>  $files
     * @return array<int, PaymentReceipt>
     */
    public function attach(Payment|CustomerPayment $parent, iterable $files, ?int $uploadedBy): array
    {
        $isSalePayment = $parent instanceof Payment;
        $tenantId = $isSalePayment ? $parent->sale?->tenant_id : $parent->tenant_id;
        $prefix = $isSalePayment ? "p-{$parent->id}" : "cg-{$parent->id}";
        $created = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $filename = Str::uuid()->toString().'.'.$ext;
            $directory = "tenants/{$tenantId}/payment_receipts/{$prefix}";

            $stored = $file->storeAs($directory, $filename, [
                'disk' => self::disk(),
                'visibility' => 'private',
            ]);
            if (! $stored) {
                continue;
            }

            $created[] = PaymentReceipt::create([
                'tenant_id' => $tenantId,
                'payment_id' => $isSalePayment ? $parent->id : null,
                'customer_payment_id' => $isSalePayment ? null : $parent->id,
                'uploaded_by' => $uploadedBy,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $stored,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize() ?: 0,
            ]);
        }

        return $created;
    }

    public function delete(PaymentReceipt $receipt): void
    {
        Storage::disk(self::disk())->delete($receipt->path);
        $receipt->delete();
    }
}
