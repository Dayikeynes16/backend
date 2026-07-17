<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterShift;
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
     * (y branch ownership si el usuario es admin-sucursal, o branch+dueño
     * si es cajero) antes de servir el archivo desde el disco privado.
     *
     * Acepta el gasto soft-deleted (withTrashed) para que la auditoría
     * pueda seguir consultando los archivos.
     */
    public function download(Expense $gasto, ExpenseAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($gasto, $attachment);

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
        $this->authorizeView($gasto, $attachment);

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
        $this->authorizeMutation($gasto, $attachment);

        // El hook 'deleting' del modelo borra el archivo físico.
        $attachment->delete();

        return back()->with('success', 'Adjunto eliminado.');
    }

    /**
     * Ver/descargar/previsualizar. admin-empresa/superadmin: cualquiera de
     * su tenant. admin-sucursal: solo de su sucursal. cajero: solo de su
     * sucursal Y sus propios gastos (mismo filtro que ya usa
     * Caja\GastoController@index: branch_id + user_id).
     */
    private function authorizeView(Expense $expense, ExpenseAttachment $attachment): void
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($expense->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($attachment->expense_id !== $expense->id || $attachment->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($user->hasRole('admin-sucursal') && ! $user->hasRole('superadmin')) {
            if ($expense->branch_id !== $user->branch_id) {
                abort(403);
            }

            return;
        }

        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            if ($expense->branch_id !== $user->branch_id || $expense->user_id !== $user->id) {
                abort(403);
            }
        }
    }

    /**
     * Eliminar. Además de authorizeView, el cajero solo puede sobre gastos
     * ligados a su turno abierto (mismo campo que ya calcula `can_manage`
     * en Caja\GastoController@index: cash_register_shift_id).
     */
    private function authorizeMutation(Expense $expense, ExpenseAttachment $attachment): void
    {
        $this->authorizeView($expense, $attachment);

        $user = Auth::user();
        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
            if (! $shift || $expense->cash_register_shift_id !== $shift->id) {
                abort(403, 'Solo puedes eliminar adjuntos de tus gastos del turno abierto.');
            }
        }
    }
}
