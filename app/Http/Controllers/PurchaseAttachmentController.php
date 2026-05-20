<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseAttachment;
use App\Services\PurchaseAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espejo de ExpenseAttachmentController. Una sola implementación atiende
 * tanto a admin-empresa como admin-sucursal — el gating fino vive en
 * authorizeAccess() (admin-sucursal solo accede a adjuntos de su sucursal).
 */
class PurchaseAttachmentController extends Controller
{
    public function download(Purchase $compra, PurchaseAttachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($compra, $attachment);

        if (! Storage::disk(PurchaseAttachmentService::disk())->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return Storage::disk(PurchaseAttachmentService::disk())
            ->download($attachment->path, $attachment->original_name, [
                'Content-Type' => $attachment->mime_type,
            ]);
    }

    public function preview(Purchase $compra, PurchaseAttachment $attachment): Response
    {
        $this->authorizeAccess($compra, $attachment);

        $disk = Storage::disk(PurchaseAttachmentService::disk());
        if (! $disk->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return response($disk->get($attachment->path), 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
            'Content-Length' => (string) $attachment->size_bytes,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Purchase $compra, PurchaseAttachment $attachment): RedirectResponse
    {
        $this->authorizeAccess($compra, $attachment);

        // El hook 'deleting' del modelo borra el archivo físico.
        $attachment->delete();

        return back()->with('success', 'Adjunto eliminado.');
    }

    private function authorizeAccess(Purchase $purchase, PurchaseAttachment $attachment): void
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($purchase->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($attachment->purchase_id !== $purchase->id || $attachment->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($user->hasRole('admin-sucursal') && ! $user->hasRole('superadmin')) {
            if ($purchase->branch_id !== $user->branch_id) {
                abort(403);
            }
        }
    }
}
