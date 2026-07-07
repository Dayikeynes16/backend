<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\PurchasePaymentService;
use Illuminate\Validation\Rule;

/**
 * Confirma un borrador de pago "a cuenta" a proveedor. Delega en
 * PurchasePaymentService::applyAccountPayment (FIFO por purchased_at, prohíbe
 * crédito, excedente como pago huérfano a favor, recalcula saldos). No exige
 * turno abierto — igual que el flujo web de empresa/sucursal. Para
 * admin-sucursal el FIFO se acota a compras de su sucursal.
 */
final class ProviderAccountPaymentDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly PurchasePaymentService $payments,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::ProviderAccountPayment;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;

        return [
            'provider_id' => [
                'required', 'integer',
                Rule::exists('providers', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
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
            'provider_id.required' => 'Selecciona el proveedor a pagar.',
            'provider_id.exists' => 'El proveedor no es válido.',
            'amount.min' => 'El monto del pago debe ser mayor a 0.',
            'payment_method.in' => 'El método de pago no aplica a proveedores.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $provider = Provider::query()->whereKey((int) $validated['provider_id'])->firstOrFail();

        // Mismo scope que el flujo web: admin-sucursal solo reparte entre
        // compras de su sucursal; admin-empresa sobre todo el tenant.
        $branchId = ($user->hasRole('admin-sucursal') && $user->branch_id) ? $user->branch_id : null;

        // applyAccountPayment re-calcula el FIFO al momento de confirmar; sus
        // ValidationException (monto <= 0, método crédito) salen como 422.
        $created = $this->payments->applyAccountPayment($provider, [
            'amount' => (float) $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'paid_at' => $validated['paid_at'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        $this->drafts->markConsumed($draft, $created[0]);

        $surplus = collect($created)->first(fn ($p) => $p->purchase_id === null);
        $appliedPayments = collect($created)->filter(fn ($p) => $p->purchase_id !== null);
        $appliedTotal = (float) $appliedPayments->sum('amount');

        $message = 'Pago a cuenta registrado: $'.number_format($appliedTotal, 2)
            .' aplicados a '.$appliedPayments->count().' compra(s) de '.$provider->name
            .($surplus ? ', excedente a favor $'.number_format((float) $surplus->amount, 2) : '').'.';

        return new DraftConfirmationResult(
            record: $created[0],
            message: $message,
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'provider_account_payment',
                'status' => 'consumed',
                'result_id' => $created[0]->id,
                'payments_created' => count($created),
                'surplus' => $surplus ? (float) $surplus->amount : 0,
            ],
        );
    }
}
