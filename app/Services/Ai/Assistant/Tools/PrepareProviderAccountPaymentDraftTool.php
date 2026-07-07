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
use App\Services\PurchasePaymentService;
use Illuminate\Support\Carbon;

/**
 * Prepara un BORRADOR de pago "a cuenta" a un proveedor: el monto se distribuye
 * FIFO entre sus compras pendientes más antiguas (purchased_at), con el posible
 * excedente a favor del proveedor como pago huérfano.
 *
 * NO registra el pago: persiste un `assistant_draft` (type=provider_account_payment)
 * con el desglose calculado por PurchasePaymentService::previewAccountPayment().
 * Al confirmar se ejecuta applyAccountPayment() (prohíbe crédito, recalcula
 * saldos). Para admin-sucursal el FIFO se acota a compras de su sucursal.
 * Complementa a `preparar_borrador_abono` (que abona a UNA compra puntual).
 */
class PrepareProviderAccountPaymentDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(
        private readonly AssistantDraftService $drafts,
        private readonly PurchasePaymentService $payments,
    ) {}

    public function name(): string
    {
        return 'preparar_pago_proveedor_cuenta';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de pago A CUENTA de un proveedor: el monto se reparte automáticamente entre sus compras pendientes más antiguas (no lo registra; el usuario debe confirmarlo). Úsala cuando el usuario quiere pagarle al proveedor SIN mencionar una compra específica. Ejemplos: "págale 5000 a Carnes del Norte", "abónale 2000 al proveedor San Juan por transferencia". Si menciona un folio de compra concreto usa preparar_borrador_abono.';
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
                'provider_name' => ['type' => ['string', 'null'], 'description' => 'Nombre del proveedor a pagar.'],
                'amount' => ['type' => ['number', 'null'], 'description' => 'Monto del pago en pesos.'],
                'payment_method' => ['type' => ['string', 'null'], 'enum' => ['cash', 'card', 'transfer'], 'description' => 'Método de pago (nunca crédito).'],
                'reference' => ['type' => ['string', 'null'], 'description' => 'Referencia del pago (folio de transferencia, etc.).'],
                'notes' => ['type' => ['string', 'null'], 'description' => 'Notas.'],
                'paid_at' => ['type' => ['string', 'null'], 'description' => 'Fecha del pago YYYY-MM-DD.'],
            ],
            'required' => ['provider_name', 'amount', 'payment_method', 'reference', 'notes', 'paid_at'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $target = $this->resolveProvider($user, $params['provider_name'] ?? null);

        return [
            'provider_id' => $target['provider_id'],
            'provider' => $target['provider'],
            'provider_candidates' => $target['candidates'],
            'provider_name' => $this->clean($params['provider_name'] ?? null, 160),
            'amount' => is_numeric($params['amount'] ?? null) ? round((float) $params['amount'], 2) : null,
            'payment_method' => in_array($params['payment_method'] ?? null, ['cash', 'card', 'transfer'], true)
                ? $params['payment_method']
                : null,
            'reference' => $this->clean($params['reference'] ?? null, 120),
            'notes' => $this->clean($params['notes'] ?? null, 500),
            'paid_at' => $this->cleanDate($params['paid_at'] ?? null),
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::ProviderAccountPayment,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['provider_id'])) {
            $missing[] = 'proveedor';
        }
        if (empty($params['amount']) || $params['amount'] <= 0) {
            $missing[] = 'monto';
        }
        if (empty($params['payment_method'])) {
            $missing[] = 'método de pago';
        }

        $alerts = [];
        $distribution = null;

        if (! empty($params['provider_id'])) {
            $provider = Provider::query()->find($params['provider_id']);
            $totalPending = $params['provider']['total_pending'] ?? 0;

            if ($provider && $totalPending <= 0) {
                $alerts[] = 'El proveedor no tiene compras con saldo pendiente; todo el monto quedaría como excedente a favor.';
            }

            if ($provider && ! empty($params['amount']) && $params['amount'] > 0) {
                $distribution = $this->payments->previewAccountPayment(
                    $provider,
                    $params['amount'],
                    $this->branchScope($user),
                );

                if ($distribution['surplus'] > 0 && $totalPending > 0) {
                    $alerts[] = 'Quedará un excedente a favor del proveedor de $'.number_format($distribution['surplus'], 2).'.';
                }
            }
        }

        $proposal = array_merge($params, [
            'paid_at' => $params['paid_at'] ?: now()->toDateString(),
            'distribution' => $distribution,
            'campos_faltantes' => $missing,
            'alertas' => $alerts,
        ]);

        $this->drafts->markReady($draft, $proposal);

        $data = $this->buildCard($draft->fresh(), $proposal, $user);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de pago a cuenta al proveedor. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'provider_account_payment',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'summary' => 'Borrador de pago a cuenta preparado. La distribución FIFO la calculó el sistema; espera a que el usuario lo confirme con el botón — tú no puedes registrarlo ni marcar deudas como pagadas.',
            ],
        );
    }

    /**
     * Resuelve el proveedor por nombre: exacto (case-insensitive) primero, luego
     * parcial. Un único match = resuelto; varios = candidatos explícitos.
     *
     * @return array{provider_id: int|null, provider: array<string, mixed>|null, candidates: array<int, array<string, mixed>>}
     */
    private function resolveProvider(User $user, ?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['provider_id' => null, 'provider' => null, 'candidates' => []];
        }

        $exact = Provider::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();

        if ($exact->count() === 1) {
            $p = $exact->first();

            return ['provider_id' => $p->id, 'provider' => $this->providerInfo($p, $user), 'candidates' => []];
        }

        $matches = $exact->count() > 1
            ? $exact
            : Provider::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($name).'%'])
                ->limit(8)
                ->get();

        if ($matches->count() === 1) {
            $p = $matches->first();

            return ['provider_id' => $p->id, 'provider' => $this->providerInfo($p, $user), 'candidates' => []];
        }

        return [
            'provider_id' => null,
            'provider' => null,
            'candidates' => $matches->map(fn (Provider $p) => $this->providerInfo($p, $user))->all(),
        ];
    }

    /**
     * Compras pendientes acotadas a la sucursal del usuario si es admin-sucursal
     * (mismo scope que usará el confirmer al ejecutar el FIFO).
     */
    private function branchScope(User $user): ?int
    {
        return ($user->hasRole('admin-sucursal') && $user->branch_id) ? $user->branch_id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function providerInfo(Provider $p, User $user): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'total_pending' => $this->totalPending($p, $user),
        ];
    }

    private function totalPending(Provider $p, User $user): float
    {
        return round((float) Purchase::query()
            ->where('provider_id', $p->id)
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->where('amount_pending', '>', 0)
            ->when($this->branchScope($user) !== null, fn ($q) => $q->where('branch_id', $this->branchScope($user)))
            ->sum('amount_pending'), 2);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function buildCard(AssistantDraft $draft, array $proposal, User $user): array
    {
        return [
            'draft_id' => $draft->id,
            'draft_type' => 'provider_account_payment',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'provider_id' => $proposal['provider_id'] ?? null,
                'provider' => $proposal['provider'] ?? null,
                'amount' => $proposal['amount'] ?? null,
                'payment_method' => $proposal['payment_method'] ?? null,
                'reference' => $proposal['reference'] ?? null,
                'notes' => $proposal['notes'] ?? null,
                'paid_at' => $proposal['paid_at'] ?? null,
                'distribution' => $proposal['distribution'] ?? null,
            ],
            'missing_fields' => $proposal['campos_faltantes'] ?? [],
            'warnings' => $proposal['alertas'] ?? [],
            'options' => [
                'providers' => $this->providerOptions($user, $proposal['provider_candidates'] ?? []),
                'payment_methods' => collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
                    ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                    ->all(),
            ],
        ];
    }

    /**
     * Opciones del select: candidatos ambiguos si los hay; si no, proveedores
     * con saldo pendiente en el scope del usuario.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function providerOptions(User $user, array $candidates): array
    {
        if (! empty($candidates)) {
            return $candidates;
        }

        $pendingByProvider = Purchase::query()
            ->where('status', '!=', PurchaseStatus::Cancelled)
            ->where('amount_pending', '>', 0)
            ->when($this->branchScope($user) !== null, fn ($q) => $q->where('branch_id', $this->branchScope($user)))
            ->selectRaw('provider_id, SUM(amount_pending) AS pending')
            ->groupBy('provider_id')
            ->orderByDesc('pending')
            ->limit(50)
            ->pluck('pending', 'provider_id');

        return Provider::query()
            ->whereIn('id', $pendingByProvider->keys())
            ->get()
            ->map(fn (Provider $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'total_pending' => round((float) $pendingByProvider[$p->id], 2),
            ])
            ->sortByDesc('total_pending')
            ->values()
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
