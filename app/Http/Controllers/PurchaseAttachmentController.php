<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterShift;
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
 * admin-empresa, admin-sucursal y cajero — el gating fino vive en
 * authorizeView()/authorizeMutation().
 */
class PurchaseAttachmentController extends Controller
{
    public function download(Purchase $compra, PurchaseAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($compra, $attachment);

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
        $this->authorizeView($compra, $attachment);

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
        $this->authorizeMutation($compra, $attachment);

        // El hook 'deleting' del modelo borra el archivo físico.
        $attachment->delete();

        return back()->with('success', 'Adjunto eliminado.');
    }

    /**
     * Ver/descargar/previsualizar. admin-empresa/superadmin: cualquiera de
     * su tenant. admin-sucursal: solo de su sucursal. cajero: solo de su
     * sucursal Y sus propias compras (created_by) — misma regla que Gastos,
     * porque Caja\PurchaseController@index también filtra su listado por
     * branch_id + created_by (no muestra todas las compras de la sucursal
     * a cualquier cajero).
     *
     * Ver docs/superpowers/specs/2026-07-17-adjuntos-unificados-design.md.
     */
    private function authorizeView(Purchase $purchase, PurchaseAttachment $attachment): void
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

            return;
        }

        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            if ($purchase->branch_id !== $user->branch_id || $purchase->created_by !== $user->id) {
                abort(403);
            }
        }
    }

    /**
     * Eliminar. Además de authorizeView, el cajero solo puede sobre compras
     * ligadas a su turno abierto (mismo campo que ya calcula `can_manage`
     * en Caja\PurchaseController@index: cash_register_shift_id).
     */
    private function authorizeMutation(Purchase $purchase, PurchaseAttachment $attachment): void
    {
        $this->authorizeView($purchase, $attachment);

        $user = Auth::user();
        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
            if (! $shift || $purchase->cash_register_shift_id !== $shift->id) {
                abort(403, 'Solo puedes eliminar adjuntos de tus compras del turno abierto.');
            }
        }
    }
}
