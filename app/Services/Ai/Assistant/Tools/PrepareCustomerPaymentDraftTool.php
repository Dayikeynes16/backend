<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\AssistantDraft;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\CustomerGlobalPaymentService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Prepara un BORRADOR de cobro global a un cliente con deuda (fiado): el monto
 * se distribuye FIFO entre sus ventas pendientes más antiguas.
 *
 * NO registra el cobro: persiste un `assistant_draft` (type=customer_global_payment)
 * con el cliente, monto, método y el desglose FIFO calculado server-side por
 * CustomerGlobalPaymentService::preview(). Al confirmar, el confirmer exige
 * turno abierto (decisión D2) y ejecuta apply(), que re-calcula la distribución
 * de forma autoritativa. La IA solo interpreta la intención; nunca calcula
 * montos ni marca deudas como pagadas.
 */
class PrepareCustomerPaymentDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(
        private readonly AssistantDraftService $drafts,
        private readonly CustomerGlobalPaymentService $globalPayments,
    ) {}

    public function name(): string
    {
        return 'preparar_cobro_cliente';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de cobro/abono de un CLIENTE con deuda (fiado); el monto se aplica automáticamente a sus ventas pendientes más antiguas (no lo registra, el usuario debe confirmarlo). Úsala EN CUANTO el usuario diga que un cliente pagó/abonó/transfirió — con los datos que tenga, sin pedir confirmaciones previas: la tarjeta es editable. INFIERE payment_method del lenguaje: "transfirió/depositó/transferencia" → transfer; "efectivo/cash/billetes" → cash; "tarjeta" → card. NO preguntes por notas ni campos opcionales. Ejemplos: "Juan Pérez pagó $1,500 en efectivo", "el Rincón transfirió $2,731".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-empresa', 'admin-sucursal', 'cajero'];
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'customer_name' => ['type' => ['string', 'null'], 'description' => 'Nombre del cliente que paga.'],
                'amount' => ['type' => ['number', 'null'], 'description' => 'Monto recibido en pesos.'],
                'payment_method' => ['type' => ['string', 'null'], 'enum' => ['cash', 'card', 'transfer'], 'description' => 'Método con el que paga el cliente.'],
                'notes' => ['type' => ['string', 'null'], 'description' => 'Notas del cobro.'],
            ],
            'required' => ['customer_name', 'amount', 'payment_method', 'notes'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $target = $this->resolveCustomer($user, $params['customer_name'] ?? null);

        return [
            'customer_id' => $target['customer_id'],
            'customer' => $target['customer'],
            'customer_candidates' => $target['candidates'],
            'customer_name' => $this->clean($params['customer_name'] ?? null, 160),
            'amount' => is_numeric($params['amount'] ?? null) ? round((float) $params['amount'], 2) : null,
            'payment_method' => in_array($params['payment_method'] ?? null, ['cash', 'card', 'transfer'], true)
                ? $params['payment_method']
                : null,
            'notes' => $this->clean($params['notes'] ?? null, 500),
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::CustomerGlobalPayment,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['customer_id'])) {
            $missing[] = 'cliente';
        }
        if (empty($params['amount']) || $params['amount'] <= 0) {
            $missing[] = 'monto';
        }
        if (empty($params['payment_method'])) {
            $missing[] = 'método de pago';
        }

        $alerts = [];
        $distribution = null;

        if (! empty($params['customer_id'])) {
            $customer = Customer::query()->find($params['customer_id']);
            $owed = $params['customer']['total_owed'] ?? 0;

            if ($customer && $owed <= 0) {
                $alerts[] = 'El cliente no tiene deuda pendiente.';
            } elseif ($customer && ! empty($params['amount']) && $params['amount'] > 0 && ! empty($params['payment_method'])) {
                $distribution = $this->globalPayments->preview($customer, $params['amount'], $params['payment_method']);

                if ($params['payment_method'] !== 'cash' && $params['amount'] > $distribution['total_pending'] + 0.001) {
                    $alerts[] = 'Con '.$params['payment_method'].' no hay cambio — el monto excede la deuda ($'.number_format($distribution['total_pending'], 2).'). Ajusta el monto antes de confirmar.';
                } elseif ($distribution['change_given'] > 0) {
                    $alerts[] = 'Se dará cambio de $'.number_format($distribution['change_given'], 2).'.';
                }
            }
        }

        $proposal = array_merge($params, [
            'distribution' => $distribution,
            'campos_faltantes' => $missing,
            'alertas' => $alerts,
        ]);

        $this->drafts->markReady($draft, $proposal);

        $data = $this->buildCard($draft->fresh(), $proposal, $user);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de cobro al cliente. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'customer_global_payment',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'resolved_customer' => $params['customer']['name'] ?? null,
                'customer_candidates' => array_map(
                    fn (array $c) => $c['name'].' (debe $'.number_format((float) $c['total_owed'], 2).')',
                    array_slice($params['customer_candidates'], 0, 5),
                ),
                'summary' => 'Borrador de cobro preparado. Si customer_candidates trae opciones, pregunta al usuario a cuál se refiere (o dile que elija en la tarjeta). La distribución FIFO la calculó el sistema; espera a que el usuario confirme con el botón — tú no puedes registrarlo ni marcar deudas como pagadas.',
            ],
        );
    }

    /**
     * Resuelve el cliente por nombre: primero match exacto (case-insensitive),
     * luego parcial. Un único match = resuelto; varios = candidatos para que el
     * usuario elija explícitamente (nunca adivinamos a quién aplicar dinero).
     *
     * @return array{customer_id: int|null, customer: array<string, mixed>|null, candidates: array<int, array<string, mixed>>}
     */
    private function resolveCustomer(User $user, ?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['customer_id' => null, 'customer' => null, 'candidates' => []];
        }

        // Búsqueda difusa en memoria sobre el scope del usuario: insensible a
        // acentos y tolerante a palabras extra ("rincón del taco" → "Rincon").
        // El catálogo de clientes de una sucursal es acotado; 500 cubre de sobra.
        $pool = $this->customerBase($user)
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'name', 'phone', 'branch_id', 'tenant_id']);

        $result = $this->fuzzyMatchByName($pool, $name, fn (Customer $c) => $c->name);

        if ($result['match']) {
            return [
                'customer_id' => $result['match']->id,
                'customer' => $this->customerInfo($result['match']),
                'candidates' => [],
            ];
        }

        return [
            'customer_id' => null,
            'customer' => null,
            'candidates' => array_map(fn (Customer $c) => $this->customerInfo($c), $result['candidates']),
        ];
    }

    /**
     * Clientes del tenant, acotados a la sucursal del usuario si es admin-sucursal.
     */
    private function customerBase(User $user): Builder
    {
        $query = Customer::query();
        if (($user->hasRole('admin-sucursal') || $user->hasRole('cajero')) && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerInfo(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'total_owed' => $this->totalOwed($c),
        ];
    }

    private function totalOwed(Customer $c): float
    {
        return round((float) Sale::query()
            ->where('customer_id', $c->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->where('amount_pending', '>', 0)
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
            'draft_type' => 'customer_global_payment',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'customer_id' => $proposal['customer_id'] ?? null,
                'customer' => $proposal['customer'] ?? null,
                'amount_received' => $proposal['amount'] ?? null,
                'method' => $proposal['payment_method'] ?? null,
                'notes' => $proposal['notes'] ?? null,
                'distribution' => $proposal['distribution'] ?? null,
            ],
            'missing_fields' => $proposal['campos_faltantes'] ?? [],
            'warnings' => $proposal['alertas'] ?? [],
            'options' => [
                'customers' => $this->debtorOptions($user, $proposal['customer_candidates'] ?? []),
                'payment_methods' => collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
                    ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                    ->all(),
            ],
        ];
    }

    /**
     * Opciones del select de cliente: los candidatos ambiguos si los hay; si no,
     * los clientes con deuda del scope del usuario.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function debtorOptions(User $user, array $candidates): array
    {
        if (! empty($candidates)) {
            return $candidates;
        }

        $owedByCustomer = Sale::query()
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->where('amount_pending', '>', 0)
            ->whereNotNull('customer_id')
            ->when(
                ($user->hasRole('admin-sucursal') || $user->hasRole('cajero')) && $user->branch_id,
                fn ($q) => $q->where('branch_id', $user->branch_id),
            )
            ->selectRaw('customer_id, SUM(amount_pending) AS owed')
            ->groupBy('customer_id')
            ->orderByDesc('owed')
            ->limit(50)
            ->pluck('owed', 'customer_id');

        return Customer::query()
            ->whereIn('id', $owedByCustomer->keys())
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'total_owed' => round((float) $owedByCustomer[$c->id], 2),
            ])
            ->sortByDesc('total_owed')
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
}
