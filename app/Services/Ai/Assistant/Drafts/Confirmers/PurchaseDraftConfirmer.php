<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\PurchaseAttachmentService;
use App\Services\Purchases\PurchaseWriter;
use Illuminate\Validation\Rule;

/**
 * Confirma un borrador de compra. Re-valida el payload editado con las MISMAS
 * reglas que la captura manual (proveedor e insumos del tenant, líneas) y crea
 * la compra vía {@see PurchaseWriter} (siembra el saldo pendiente y audita).
 * La sucursal se fuerza según el rol.
 */
final class PurchaseDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly PurchaseWriter $writer,
        private readonly AssistantDraftService $drafts,
        private readonly PurchaseAttachmentService $attachments,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::Purchase;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        // El rol de la ruta ya restringe a admin-empresa/admin-sucursal; la
        // sucursal se fuerza en confirm().
        return true;
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;

        $rules = [
            'provider_id' => [
                'required', 'integer',
                Rule::exists('providers', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'invoice_number' => 'nullable|string|max:60',
            'purchased_at' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.purchase_product_id' => [
                'nullable', 'integer',
                Rule::exists('purchase_products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'items.*.concept' => 'required|string|max:160',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit' => 'required|string|max:10',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
        ];

        if (! $user->hasRole('admin-sucursal')) {
            $rules['branch_id'] = [
                'required', 'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'provider_id.required' => 'Selecciona un proveedor.',
            'provider_id.exists' => 'El proveedor no es válido.',
            'branch_id.required' => 'Selecciona una sucursal.',
            'items.required' => 'La compra necesita al menos un concepto.',
            'items.min' => 'La compra necesita al menos un concepto.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $branchId = $user->hasRole('admin-sucursal')
            ? (int) $user->branch_id
            : (int) $validated['branch_id'];

        $purchase = $this->writer->create(app('tenant'), $user, $validated, $branchId);

        // Mueve la factura (si el borrador vino de una imagen) a la compra.
        if (! empty($draft->attachment_paths)) {
            $this->attachments->attachFromDraftPaths($purchase, $draft->attachment_paths, $user->id);
        }

        $this->drafts->markConsumed($draft, $purchase);

        return new DraftConfirmationResult(
            record: $purchase,
            message: 'Compra registrada.',
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'purchase',
                'status' => 'consumed',
                'result_id' => $purchase->id,
            ],
        );
    }
}
