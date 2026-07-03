<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Enums\PurchaseStatus;
use App\Models\AssistantDraft;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\PurchasePaymentService;
use Illuminate\Validation\Rule;

/**
 * Confirma un borrador de abono a una compra. Delega en PurchasePaymentService::
 * applyPayment (bloqueo de la compra, no sobre-pago, prohíbe crédito, recalcula
 * saldo y audita). Nunca marca una deuda como pagada sin esta confirmación
 * explícita del usuario. admin-sucursal sólo abona a compras de su sucursal.
 */
final class PayablePaymentDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly PurchasePaymentService $payments,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::PayablePayment;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;

        $purchaseRule = Rule::exists('purchases', 'id')->where(function ($q) use ($tenantId, $user) {
            $q->where('tenant_id', $tenantId)->where('status', '!=', PurchaseStatus::Cancelled->value);
            if ($user->hasRole('admin-sucursal') && $user->branch_id) {
                $q->where('branch_id', $user->branch_id);
            }
        });

        return [
            'purchase_id' => ['required', 'integer', $purchaseRule],
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer'])],
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:500',
            'paid_at' => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_id.required' => 'Selecciona la compra a la que abonar.',
            'purchase_id.exists' => 'La compra no es válida.',
            'amount.min' => 'El monto del abono debe ser mayor a 0.',
            'payment_method.in' => 'El método de pago no aplica a proveedores.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $purchase = Purchase::query()->whereKey((int) $validated['purchase_id'])->firstOrFail();

        // Defensa en profundidad: admin-sucursal jamás abona a otra sucursal.
        if ($user->hasRole('admin-sucursal') && $purchase->branch_id !== $user->branch_id) {
            abort(403);
        }

        $payment = $this->payments->applyPayment($purchase, [
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'paid_at' => $validated['paid_at'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'user_id' => $user->id,
        ]);

        $this->drafts->markConsumed($draft, $payment);

        return new DraftConfirmationResult(
            record: $payment,
            message: 'Abono registrado.',
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'payable_payment',
                'status' => 'consumed',
                'result_id' => $payment->id,
            ],
        );
    }
}
