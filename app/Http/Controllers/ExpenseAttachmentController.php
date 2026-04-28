<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseAttachmentController extends Controller
{
    /**
     * Descarga autenticada de un adjunto. Valida tenant ownership
     * (y branch ownership si el usuario es admin-sucursal) antes de
     * servir el archivo desde el disco privado.
     *
     * Acepta el gasto soft-deleted (withTrashed) para que la auditoría
     * pueda seguir consultando los archivos.
     */
    public function download(Expense $gasto, ExpenseAttachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($gasto, $attachment);

        if (! Storage::disk(ExpenseAttachmentService::disk())->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return Storage::disk(ExpenseAttachmentService::disk())
            ->download($attachment->path, $attachment->original_name, [
                'Content-Type' => $attachment->mime_type,
            ]);
    }

    /**
     * Preview en línea (no descarga). Sirve el archivo con
     * Content-Disposition: inline para que el navegador lo muestre
     * directamente (img tag o iframe). Usado por el viewer modal.
     */
    public function preview(Expense $gasto, ExpenseAttachment $attachment): Response
    {
        $this->authorizeAccess($gasto, $attachment);

        $disk = Storage::disk(ExpenseAttachmentService::disk());
        if (! $disk->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        $contents = $disk->get($attachment->path);

        return response($contents, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
            'Content-Length' => (string) $attachment->size_bytes,
            // Cache breve para no recargar el archivo cada vez que el usuario
            // abre el viewer dentro de la misma sesión.
            'Cache-Control' => 'private, max-age=300',
            // Endurecimiento: el navegador no debe ejecutar el archivo como otra cosa.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Expense $gasto, ExpenseAttachment $attachment): RedirectResponse
    {
        $this->authorizeAccess($gasto, $attachment);

        // El hook 'deleting' del modelo borra el archivo físico.
        $attachment->delete();

        return back()->with('success', 'Adjunto eliminado.');
    }

    private function authorizeAccess(Expense $expense, ExpenseAttachment $attachment): void
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($expense->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($attachment->expense_id !== $expense->id || $attachment->tenant_id !== $tenant->id) {
            abort(403);
        }

        // Admin-sucursal sólo puede acceder a adjuntos de gastos de su sucursal.
        if ($user->hasRole('admin-sucursal') && ! $user->hasRole('superadmin')) {
            if ($expense->branch_id !== $user->branch_id) {
                abort(403);
            }
        }
    }
}
