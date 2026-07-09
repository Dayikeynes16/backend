<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;

/**
 * Prepara un BORRADOR de alta de CLIENTE (persona que COMPRA en la carnicería,
 * puede tener fiado). No confundir con proveedor. Detecta posibles duplicados
 * por nombre/teléfono en el scope. Branch forzado para admin-sucursal/cajero.
 */
class PrepareCustomerDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function name(): string
    {
        return 'preparar_borrador_cliente';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de alta de un CLIENTE nuevo (persona o negocio que te COMPRA, puede tener fiado). NO es para proveedores (a quienes tú les compras) — para eso usa preparar_borrador_proveedor. Ejemplos: "agrega a Cachorro como cliente", "da de alta a la señora María con teléfono 5512345678".';
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
                'name' => ['type' => ['string', 'null'], 'description' => 'Nombre del cliente.'],
                'phone' => ['type' => ['string', 'null'], 'description' => 'Teléfono.'],
                'notes' => ['type' => ['string', 'null'], 'description' => 'Notas.'],
                'branch_name' => ['type' => ['string', 'null'], 'description' => 'Sucursal (solo admin-empresa; para otros roles se ignora).'],
            ],
            'required' => ['name', 'phone', 'notes', 'branch_name'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $clean = fn ($v, $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;

        return [
            'name' => $clean($params['name'] ?? null, 160),
            'phone' => $clean($params['phone'] ?? null, 20),
            'notes' => $clean($params['notes'] ?? null, 500),
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $draft = $this->drafts->create(
            AssistantDraftType::Customer,
            app('tenant'),
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['name'])) {
            $missing[] = 'nombre';
        }
        if (! $params['branch_id'] && ! $user->branch_id) {
            $missing[] = 'sucursal';
        }

        // Posibles duplicados en el scope (mismo patrón que proveedores).
        $duplicates = [];
        if ($params['name']) {
            $pool = Customer::query()
                ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
                ->orderByDesc('id')->limit(500)->get(['id', 'name', 'phone', 'branch_id', 'tenant_id']);
            $match = $this->fuzzyMatchByName($pool, $params['name'], fn (Customer $c) => $c->name);
            $found = array_filter([$match['match'], ...$match['candidates']]);
            $duplicates = collect($found)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone])->take(3)->values()->all();
        }

        $proposal = array_merge($params, ['campos_faltantes' => $missing, 'alertas' => []]);
        $this->drafts->markReady($draft, $proposal);

        $branches = Branch::query()->where('status', 'active')->orderBy('name')
            ->when(($user->hasRole('admin-sucursal') || $user->hasRole('cajero')) && $user->branch_id, fn ($q) => $q->where('id', $user->branch_id))
            ->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all();

        $data = [
            'draft_id' => $draft->fresh()->id,
            'draft_type' => 'customer',
            'status' => $draft->fresh()->status->value,
            'expires_at' => $draft->fresh()->expires_at?->toIso8601String(),
            'preview' => [
                'name' => $params['name'],
                'phone' => $params['phone'],
                'notes' => $params['notes'],
                'branch_id' => $params['branch_id'] ?? $user->branch_id,
            ],
            'missing_fields' => $missing,
            'warnings' => [],
            'duplicates' => $duplicates,
            'options' => ['branches' => $branches],
        ];

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de CLIENTE nuevo. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'customer',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'possible_duplicates' => array_column($duplicates, 'name'),
                'summary' => 'Borrador de CLIENTE (no proveedor) preparado. Espera a que el usuario lo confirme con el botón.',
            ],
        );
    }
}
