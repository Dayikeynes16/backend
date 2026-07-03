<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Prepara un BORRADOR de abono (pago) a una compra con saldo pendiente para que
 * el usuario lo confirme.
 *
 * NO registra el pago: persiste un `assistant_draft` (type=payable_payment) con
 * la compra objetivo, el monto y el método. Al confirmar se ejecuta vía
 * PurchasePaymentService::applyPayment (bloquea la compra, valida que no exceda
 * el saldo, prohíbe crédito, recalcula y audita). admin-sucursal sólo puede
 * abonar a compras de su sucursal.
 */
class PreparePayablePaymentDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function name(): string
    {
        return 'preparar_borrador_abono';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de abono/pago a una compra pendiente de un proveedor (no lo registra) para que el usuario lo confirme. Úsala cuando el usuario quiere pagar/abonar a un proveedor o a una compra. Ejemplos: "abona 1000 a la compra CMP-2026-00042", "págale 500 en efectivo al proveedor San Juan".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-empresa', 'admin-sucursal'];
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'purchase_folio' => ['type' => ['string', 'null'], 'description' => 'Folio de la compra a pagar, p.ej. "CMP-2026-00042".'],
                'provider_name' => ['type' => ['string', 'null'], 'description' => 'Nombre del proveedor si no se dio el folio.'],
                'amount' => ['type' => ['number', 'null'], 'description' => 'Monto del abono en pesos.'],
                'payment_method' => ['type' => ['string', 'null'], 'enum' => ['cash', 'card', 'transfer'], 'description' => 'Método de pago (nunca crédito).'],
                'reference' => ['type' => ['string', 'null'], 'description' => 'Referencia del pago (folio de transferencia, etc.).'],
                'notes' => ['type' => ['string', 'null'], 'description' => 'Notas.'],
                'paid_at' => ['type' => ['string', 'null'], 'description' => 'Fecha del pago YYYY-MM-DD.'],
            ],
            'required' => ['purchase_folio', 'provider_name', 'amount', 'payment_method', 'reference', 'notes', 'paid_at'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $target = $this->resolveTarget($user, $params['purchase_folio'] ?? null, $params['provider_name'] ?? null);

        return [
            'purchase_id' => $target['purchase_id'],
            'purchase' => $target['purchase'],
            'purchase_candidates' => $target['candidates'],
            'provider_name' => $this->clean($params['provider_name'] ?? null, 160),
            'amount' => is_numeric($params['amount'] ?? null) ? round((float) $params['amount'], 2) : null,
            'payment_method' => $this->cleanMethod($params['payment_method'] ?? null),
            'reference' => $this->clean($params['reference'] ?? null, 120),
            'notes' => $this->clean($params['notes'] ?? null, 500),
            'paid_at' => $this->cleanDate($params['paid_at'] ?? null),
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::PayablePayment,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['purchase_id'])) {
            $missing[] = 'compra';
        }
        if (empty($params['amount']) || $params['amount'] <= 0) {
            $missing[] = 'monto';
        }
        if (empty($params['payment_method'])) {
            $missing[] = 'método de pago';
        }

        $alerts = [];
        $pending = $params['purchase']['amount_pending'] ?? null;
        if ($pending !== null && ! empty($params['amount']) && $params['amount'] > $pending + 0.001) {
            $alerts[] = 'El monto ($'.number_format((float) $params['amount'], 2).') excede el saldo pendiente de la compra ($'.number_format((float) $pending, 2).').';
        }

        $proposal = array_merge($params, [
            'paid_at' => $params['paid_at'] ?: now()->toDateString(),
            'campos_faltantes' => $missing,
            'alertas' => $alerts,
        ]);

        $this->drafts->markReady($draft, $proposal);

        $data = $this->buildCard($draft->fresh(), $proposal, $user);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de abono. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'payable_payment',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'summary' => 'Borrador de abono preparado. Espera a que el usuario lo confirme con el botón; tú no puedes registrarlo. NUNCA marques una deuda como pagada por tu cuenta.',
            ],
        );
    }

    /**
     * @return array{purchase_id: int|null, purchase: array<string, mixed>|null, candidates: array<int, array<string, mixed>>}
     */
    private function resolveTarget(User $user, ?string $folio, ?string $providerName): array
    {
        $folio = trim((string) $folio);
        if ($folio !== '') {
            $p = $this->pendingBase($user)
                ->whereRaw('LOWER(folio) = ?', [mb_strtolower($folio)])
                ->with('provider:id,name')
                ->first();
            if ($p) {
                return ['purchase_id' => $p->id, 'purchase' => $this->purchaseInfo($p), 'candidates' => []];
            }
        }

        $providerName = trim((string) $providerName);
        if ($providerName !== '') {
            $provider = Provider::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($providerName)])->first(['id']);
            if ($provider) {
                $pending = $this->pendingBase($user)
                    ->where('provider_id', $provider->id)
                    ->where('amount_pending', '>', 0)
                    ->orderBy('purchased_at')
                    ->with('provider:id,name')
                    ->limit(20)
                    ->get();

                if ($pending->count() === 1) {
                    $p = $pending->first();

                    return ['purchase_id' => $p->id, 'purchase' => $this->purchaseInfo($p), 'candidates' => []];
                }

                return [
                    'purchase_id' => null,
                    'purchase' => null,
                    'candidates' => $pending->map(fn (Purchase $p) => $this->purchaseInfo($p))->all(),
                ];
            }
        }

        return ['purchase_id' => null, 'purchase' => null, 'candidates' => []];
    }

    /**
     * Compras no canceladas, filtradas por la sucursal del usuario si es admin-sucursal.
     */
    private function pendingBase(User $user): Builder
    {
        $query = Purchase::query()->where('status', '!=', PurchaseStatus::Cancelled);
        if ($user->hasRole('admin-sucursal') && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseInfo(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'folio' => $p->folio,
            'provider_name' => $p->provider?->name,
            'total' => (float) $p->total,
            'amount_pending' => (float) $p->amount_pending,
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function buildCard(AssistantDraft $draft, array $proposal, User $user): array
    {
        return [
            'draft_id' => $draft->id,
            'draft_type' => 'payable_payment',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'purchase_id' => $proposal['purchase_id'] ?? null,
                'purchase' => $proposal['purchase'] ?? null,
                'amount' => $proposal['amount'] ?? null,
                'payment_method' => $proposal['payment_method'] ?? null,
                'reference' => $proposal['reference'] ?? null,
                'notes' => $proposal['notes'] ?? null,
                'paid_at' => $proposal['paid_at'] ?? null,
            ],
            'missing_fields' => $proposal['campos_faltantes'] ?? [],
            'warnings' => $proposal['alertas'] ?? [],
            'options' => [
                'purchases' => $this->pendingPurchaseOptions($user),
                'payment_methods' => collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
                    ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                    ->all(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingPurchaseOptions(User $user): array
    {
        return $this->pendingBase($user)
            ->where('amount_pending', '>', 0)
            ->orderByDesc('purchased_at')
            ->with('provider:id,name')
            ->limit(50)
            ->get()
            ->map(fn (Purchase $p) => $this->purchaseInfo($p))
            ->all();
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    private function cleanMethod(mixed $value): ?string
    {
        return in_array($value, ['cash', 'card', 'transfer'], true) ? $value : null;
    }

    private function cleanDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
