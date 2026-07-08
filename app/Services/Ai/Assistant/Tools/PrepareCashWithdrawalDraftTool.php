<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Models\CashRegisterShift;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;

/**
 * Prepara un BORRADOR de retiro de efectivo de la caja. NO lo registra: al
 * confirmar, el confirmer exige turno abierto propio y cuelga el retiro de ese
 * turno — exactamente igual que el flujo web (D6: admin-sucursal y cajero).
 */
class PrepareCashWithdrawalDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function name(): string
    {
        return 'preparar_retiro_caja';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de retiro de efectivo de la caja del turno abierto (no lo registra; el usuario debe confirmarlo). Úsala cuando el usuario quiere sacar/retirar dinero de la caja. Ejemplos: "retira 500 de la caja para gasolina", "saca 200 pesos, se los llevó el patrón".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-sucursal', 'cajero'];
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'amount' => ['type' => ['number', 'null'], 'description' => 'Monto a retirar en pesos.'],
                'reason' => ['type' => ['string', 'null'], 'description' => 'Motivo del retiro (obligatorio para confirmar).'],
            ],
            'required' => ['amount', 'reason'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        return [
            'amount' => is_numeric($params['amount'] ?? null) ? round((float) $params['amount'], 2) : null,
            'reason' => $this->clean($params['reason'] ?? null, 255),
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::CashWithdrawal,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['amount']) || $params['amount'] <= 0) {
            $missing[] = 'monto';
        }
        if (empty($params['reason'])) {
            $missing[] = 'motivo';
        }

        $shift = CashRegisterShift::query()
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        $alerts = [];
        if (! $shift) {
            $alerts[] = 'No tienes un turno abierto: necesitas abrir turno antes de confirmar el retiro.';
        }

        $proposal = array_merge($params, [
            'shift_id' => $shift?->id,
            'campos_faltantes' => $missing,
            'alertas' => $alerts,
        ]);

        $this->drafts->markReady($draft, $proposal);

        $data = [
            'draft_id' => $draft->fresh()->id,
            'draft_type' => 'cash_withdrawal',
            'status' => $draft->fresh()->status->value,
            'expires_at' => $draft->fresh()->expires_at?->toIso8601String(),
            'preview' => [
                'amount' => $params['amount'],
                'reason' => $params['reason'],
                'shift' => $shift ? [
                    'id' => $shift->id,
                    'opened_at' => $shift->opened_at?->toIso8601String(),
                ] : null,
            ],
            'missing_fields' => $missing,
            'warnings' => $alerts,
            'options' => [],
        ];

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de retiro de caja. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'cash_withdrawal',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'summary' => 'Borrador de retiro preparado. Espera a que el usuario lo confirme con el botón; tú no puedes registrarlo.',
            ],
        );
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }
}
