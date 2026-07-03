<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Enums\ProviderType;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\Providers\ProviderWriter;

/**
 * Prepara un BORRADOR de alta de proveedor para que el usuario lo confirme.
 *
 * NO crea el proveedor: persiste un `assistant_draft` (type=provider) y, ANTES
 * de proponer el alta, busca posibles duplicados (nombre exacto/parecido o RFC)
 * y los muestra en la tarjeta. La confirmación es una 2ª petición HTTP del
 * usuario. No se autocrea un proveedor sólo porque un nombre no tenga match.
 */
class PrepareProviderDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function name(): string
    {
        return 'preparar_borrador_proveedor';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR para dar de alta un proveedor (no lo crea) y muestra posibles duplicados existentes. Úsala cuando el usuario quiere registrar/agregar un proveedor nuevo. Ejemplos: "agrega al proveedor Distribuidora La Unión con teléfono 55...", "da de alta a Carnes del Norte".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-empresa', 'admin-sucursal'];
    }

    public function authorize(User $user, array $params): bool
    {
        // admin-sucursal sólo si su sucursal tiene habilitado el catálogo de
        // proveedores (mismo gate que el middleware branch.feature).
        return ProviderWriter::canManage($user);
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => ['string', 'null'], 'description' => 'Nombre del proveedor.'],
                'type' => ['type' => ['string', 'null'], 'enum' => ['ganadero', 'mayorista_carne', 'insumos', 'servicios', 'otro'], 'description' => 'Tipo de proveedor si se puede inferir.'],
                'phone' => ['type' => ['string', 'null'], 'description' => 'Teléfono.'],
                'email' => ['type' => ['string', 'null'], 'description' => 'Correo.'],
                'rfc' => ['type' => ['string', 'null'], 'description' => 'RFC.'],
                'address' => ['type' => ['string', 'null'], 'description' => 'Dirección.'],
                'notes' => ['type' => ['string', 'null'], 'description' => 'Notas.'],
            ],
            'required' => ['name', 'type', 'phone', 'email', 'rfc', 'address', 'notes'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        return [
            'name' => $this->clean($params['name'] ?? null, 160),
            'type' => $this->cleanType($params['type'] ?? null),
            'phone' => $this->clean($params['phone'] ?? null, 40),
            'email' => $this->clean($params['email'] ?? null, 160),
            'rfc' => $this->clean($params['rfc'] ?? null, 20),
            'address' => $this->clean($params['address'] ?? null, 500),
            'notes' => $this->clean($params['notes'] ?? null, 1000),
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::Provider,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $duplicates = $this->findDuplicates($params['name'] ?? null, $params['rfc'] ?? null);

        $missing = [];
        if (empty($params['name'])) {
            $missing[] = 'nombre';
        }
        if (empty($params['type'])) {
            $missing[] = 'tipo';
        }

        $proposal = array_merge($params, ['campos_faltantes' => $missing]);
        $this->drafts->markReady($draft, $proposal);

        $data = $this->buildCard($draft->fresh(), $params, $missing, $duplicates);

        $dupNames = array_map(fn ($d) => $d['name'], $duplicates);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de proveedor. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'provider',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'possible_duplicates' => $dupNames,
                'summary' => $dupNames === []
                    ? 'Borrador de proveedor preparado. Espera a que el usuario lo confirme con el botón; tú no puedes confirmarlo.'
                    : 'Borrador de proveedor preparado, pero hay proveedores parecidos ('.implode(', ', $dupNames).'). Sugiere al usuario revisarlos antes de confirmar; tú no puedes confirmarlo.',
            ],
        );
    }

    /**
     * Busca proveedores existentes con nombre exacto/parecido o mismo RFC.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findDuplicates(?string $name, ?string $rfc): array
    {
        $name = trim((string) $name);
        $rfc = trim((string) $rfc);
        if ($name === '' && $rfc === '') {
            return [];
        }

        return Provider::query()
            ->where(function ($w) use ($name, $rfc) {
                if ($name !== '') {
                    $w->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                    foreach (preg_split('/\s+/', mb_strtolower($name)) ?: [] as $token) {
                        if (mb_strlen($token) >= 3) {
                            $w->orWhereRaw('LOWER(name) LIKE ?', ['%'.$token.'%']);
                        }
                    }
                }
                if ($rfc !== '') {
                    $w->orWhereRaw('LOWER(rfc) = ?', [mb_strtolower($rfc)]);
                }
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'phone', 'rfc', 'type'])
            ->map(fn (Provider $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'phone' => $p->phone,
                'rfc' => $p->rfc,
                'type' => $p->type?->label(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, string>  $missing
     * @param  array<int, array<string, mixed>>  $duplicates
     * @return array<string, mixed>
     */
    private function buildCard(AssistantDraft $draft, array $params, array $missing, array $duplicates): array
    {
        return [
            'draft_id' => $draft->id,
            'draft_type' => 'provider',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'name' => $params['name'] ?? null,
                'type' => $params['type'] ?? null,
                'phone' => $params['phone'] ?? null,
                'email' => $params['email'] ?? null,
                'rfc' => $params['rfc'] ?? null,
                'address' => $params['address'] ?? null,
                'notes' => $params['notes'] ?? null,
            ],
            'missing_fields' => $missing,
            'duplicates' => $duplicates,
            'options' => [
                'types' => array_map(
                    fn (ProviderType $t) => ['value' => $t->value, 'label' => $t->label()],
                    ProviderType::cases(),
                ),
            ],
        ];
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    private function cleanType(mixed $value): ?string
    {
        return is_string($value) && ProviderType::tryFrom($value) !== null ? $value : null;
    }
}
