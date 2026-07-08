<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;

/**
 * Confirma un borrador de retiro de caja. Exige turno abierto propio y cuelga
 * el retiro de ese turno (mismas reglas que WithdrawalController en la web).
 */
final class CashWithdrawalDraftConfirmer implements DraftConfirmer
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::CashWithdrawal;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return $user->hasRole('admin-sucursal') || $user->hasRole('cajero') || $user->hasRole('superadmin');
    }

    public function rules(User $user): array
    {
        return [
            'amount' => 'required|numeric|gt:0|max:99999999.99',
            'reason' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Indica el monto a retirar.',
            'amount.gt' => 'El monto debe ser mayor a 0.',
            'reason.required' => 'Indica el motivo del retiro.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $shift = CashRegisterShift::query()
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        if (! $shift) {
            abort(403, 'Debes tener un turno abierto para registrar retiros.');
        }

        $withdrawal = CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'amount' => round((float) $validated['amount'], 2),
            'reason' => $validated['reason'],
            'created_at' => now(),
        ]);

        $this->drafts->markConsumed($draft, $withdrawal);

        $message = 'Retiro de $'.number_format((float) $withdrawal->amount, 2).' registrado en tu turno.';

        return new DraftConfirmationResult(
            record: $withdrawal,
            message: $message,
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'cash_withdrawal',
                'status' => 'consumed',
                'result_id' => $withdrawal->id,
            ],
        );
    }
}
