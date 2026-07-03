<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\Branch;
use App\Models\Provider;
use App\Models\User;
use App\Services\Ai\AiPurchaseDraftService;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Prepara un BORRADOR de compra a proveedor para que el usuario lo confirme.
 *
 * NO crea la compra: persiste un `assistant_draft` (type=purchase) con proveedor,
 * líneas y total. Empareja el proveedor por nombre (no lo inventa: si no existe,
 * lo marca faltante y sugiere crearlo). Los insumos de cada línea se resuelven
 * por nombre al confirmar (find-or-create, igual que la captura manual). La compra
 * siembra el saldo pendiente = total (una cuenta por pagar implícita).
 */
class PreparePurchaseDraftTool extends AbstractPrepareDraftTool
{
    private const UNITS = ['kg', 'g', 'l', 'ml', 'pieza', 'caja', 'bulto', 'cabeza'];

    public function __construct(
        private readonly AssistantDraftService $drafts,
        private readonly AiPurchaseDraftService $purchaseService,
    ) {}

    public function name(): string
    {
        return 'preparar_borrador_compra';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de compra a un proveedor (no la registra) para que el usuario la confirme. Úsala cuando el usuario describe una compra por texto O adjunta la foto de una factura/nota. Ejemplo: "compré 50 kilos de cerdo a Proveedor San Juan a 90 el kilo, quedó pendiente de pago".';
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
                'provider_name' => ['type' => ['string', 'null'], 'description' => 'Nombre del proveedor tal como lo dijo el usuario.'],
                'invoice_number' => ['type' => ['string', 'null'], 'description' => 'Folio/número de factura del proveedor.'],
                'purchased_at' => ['type' => ['string', 'null'], 'description' => 'Fecha de la compra YYYY-MM-DD.'],
                'notes' => ['type' => ['string', 'null'], 'description' => 'Notas.'],
                'branch_name' => ['type' => ['string', 'null'], 'description' => 'Sucursal. Para admin-sucursal se ignora (se usa la suya).'],
                'items' => [
                    'type' => 'array',
                    'description' => 'Líneas de la compra.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'concept' => ['type' => 'string', 'description' => 'Concepto/insumo, p.ej. "Carne de cerdo".'],
                            'quantity' => ['type' => 'number', 'description' => 'Cantidad.'],
                            'unit' => ['type' => 'string', 'description' => 'Unidad: kg, g, l, ml, pieza, caja, bulto, cabeza.'],
                            'unit_price' => ['type' => 'number', 'description' => 'Precio unitario.'],
                        ],
                        'required' => ['concept', 'quantity', 'unit', 'unit_price'],
                    ],
                ],
            ],
            'required' => ['provider_name', 'invoice_number', 'purchased_at', 'notes', 'branch_name', 'items'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $provider = $this->matchProvider($params['provider_name'] ?? null);

        return [
            'provider_id' => $provider['provider_id'],
            'provider_name' => $provider['provider_name'],
            'provider_matched' => $provider['matched'],
            'provider_candidates' => $provider['candidates'],
            'invoice_number' => $this->clean($params['invoice_number'] ?? null, 60),
            'purchased_at' => $this->cleanDate($params['purchased_at'] ?? null),
            'notes' => $this->clean($params['notes'] ?? null, 2000),
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'items' => $this->sanitizeItems($params['items'] ?? []),
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');
        $images = $context->images();

        $draft = $this->drafts->create(
            AssistantDraftType::Purchase,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
            images: $images,
        );

        // Factura por imagen: extraemos proveedor + líneas con visión y
        // sobreescribimos los params (la extracción es la fuente autoritativa).
        $telemetry = [];
        if ($images !== []) {
            try {
                $extracted = $this->purchaseService->extractProposal(
                    $tenant,
                    $user,
                    (string) $context->userMessage->content,
                    $draft->fresh()->attachment_paths ?? [],
                );
            } catch (Throwable $e) {
                $this->drafts->markFailed($draft, $e->getMessage());
                throw $e;
            }
            $params = $this->mapExtraction($user, $extracted['proposal'], $params);
            $telemetry = $extracted['telemetry'];
        }

        $items = $params['items'] ?? [];
        $total = round(array_sum(array_map(fn ($l) => (float) $l['subtotal'], $items)), 2);

        $missing = [];
        if (empty($params['provider_id'])) {
            $missing[] = 'proveedor';
        }
        if ($items === []) {
            $missing[] = 'conceptos';
        }
        if (empty($params['branch_id'])) {
            $missing[] = 'sucursal';
        }

        $alerts = $params['_alerts'] ?? [];
        unset($params['_alerts']);
        if (empty($params['provider_id']) && ! empty($params['provider_name'])) {
            $alerts[] = 'El proveedor "'.$params['provider_name'].'" no está registrado. Selecciónalo de la lista o créalo primero.';
        }

        $proposal = array_merge($params, [
            'purchased_at' => $params['purchased_at'] ?: now()->toDateString(),
            'total' => $total,
            'campos_faltantes' => $missing,
            'alertas' => $alerts,
        ]);

        $this->drafts->markReady($draft, $proposal, $telemetry);

        $data = $this->buildCard($draft->fresh(), $proposal, $user);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de compra. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'purchase',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'provider_matched' => (bool) ($params['provider_matched'] ?? false),
                'total' => $total,
                'summary' => 'Borrador de compra preparado. Espera a que el usuario lo confirme con el botón; tú no puedes confirmarlo.',
            ],
        );
    }

    /**
     * Mapea la propuesta parseada de AiPurchaseProposalParser a la estructura de
     * params de esta tool. La sucursal se fuerza por rol (nunca del modelo).
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $textParams
     * @return array<string, mixed>
     */
    private function mapExtraction(User $user, array $parsed, array $textParams): array
    {
        $prov = $parsed['proveedor'] ?? null;
        if (is_array($prov) && ! empty($prov['id'])) {
            $model = Provider::query()->find((int) $prov['id']);
            $provider = [
                'provider_id' => $model?->id,
                'provider_name' => $model?->name ?? ($prov['nombre'] ?? null),
                'matched' => $model !== null,
                'candidates' => [],
            ];
        } else {
            $name = is_array($prov) ? ($prov['nombre'] ?? null) : null;
            $provider = $this->matchProvider($name ?: ($textParams['provider_name'] ?? null));
        }

        $branch = null;
        if ($user->hasRole('admin-sucursal')) {
            $branch = $user->branch_id ? Branch::query()->find($user->branch_id) : null;
        } elseif (! empty($parsed['branch_id'])) {
            $branch = Branch::query()->find((int) $parsed['branch_id']);
        }

        $items = array_map(fn ($l) => [
            'concept' => $l['concepto'] ?? '',
            'quantity' => $l['quantity'] ?? 0,
            'unit' => $l['unit'] ?? 'kg',
            'unit_price' => $l['unit_price'] ?? 0,
            'subtotal' => $l['subtotal'] ?? 0,
        ], $parsed['lineas'] ?? []);

        return [
            'provider_id' => $provider['provider_id'],
            'provider_name' => $provider['provider_name'],
            'provider_matched' => $provider['matched'],
            'provider_candidates' => $provider['candidates'],
            'invoice_number' => $parsed['invoice_number'] ?? null,
            'purchased_at' => $parsed['purchased_at'] ?? null,
            'notes' => $parsed['notas'] ?? ($textParams['notes'] ?? null),
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'items' => $items,
            '_alerts' => $parsed['alertas'] ?? [],
        ];
    }

    /**
     * @return array{provider_id: int|null, provider_name: string|null, matched: bool, candidates: array<int, array<string, mixed>>}
     */
    private function matchProvider(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['provider_id' => null, 'provider_name' => null, 'matched' => false, 'candidates' => []];
        }

        $exact = Provider::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first(['id', 'name']);
        if ($exact) {
            return ['provider_id' => $exact->id, 'provider_name' => $exact->name, 'matched' => true, 'candidates' => []];
        }

        $candidates = Provider::query()
            ->where(function ($w) use ($name) {
                foreach (preg_split('/\s+/', mb_strtolower($name)) ?: [] as $token) {
                    if (mb_strlen($token) >= 3) {
                        $w->orWhereRaw('LOWER(name) LIKE ?', ['%'.$token.'%']);
                    }
                }
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name'])
            ->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name])
            ->all();

        return ['provider_id' => null, 'provider_name' => $name, 'matched' => false, 'candidates' => $candidates];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $line) {
            if (! is_array($line)) {
                continue;
            }
            $concept = $this->clean($line['concept'] ?? null, 160);
            $qty = is_numeric($line['quantity'] ?? null) ? round((float) $line['quantity'], 3) : 0.0;
            $price = is_numeric($line['unit_price'] ?? null) ? round((float) $line['unit_price'], 4) : 0.0;
            if ($concept === null || $qty <= 0) {
                continue;
            }
            $unit = $this->cleanUnit($line['unit'] ?? null);
            $out[] = [
                'concept' => $concept,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => max(0.0, $price),
                'subtotal' => round($qty * max(0.0, $price), 2),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function buildCard(AssistantDraft $draft, array $proposal, User $user): array
    {
        return [
            'draft_id' => $draft->id,
            'draft_type' => 'purchase',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'provider_id' => $proposal['provider_id'] ?? null,
                'provider_name' => $proposal['provider_name'] ?? null,
                'provider_matched' => (bool) ($proposal['provider_matched'] ?? false),
                'invoice_number' => $proposal['invoice_number'] ?? null,
                'purchased_at' => $proposal['purchased_at'] ?? null,
                'notes' => $proposal['notes'] ?? null,
                'branch_id' => $proposal['branch_id'] ?? null,
                'branch_name' => $proposal['branch_name'] ?? null,
                'items' => $proposal['items'] ?? [],
                'total' => $proposal['total'] ?? 0,
            ],
            'missing_fields' => $proposal['campos_faltantes'] ?? [],
            'warnings' => $proposal['alertas'] ?? [],
            'provider_candidates' => $proposal['provider_candidates'] ?? [],
            'attachments' => array_map(fn ($a) => [
                'original_name' => $a['original_name'] ?? null,
                'mime_type' => $a['mime_type'] ?? null,
            ], $draft->attachment_paths ?? []),
            'options' => [
                'providers' => $this->providerOptions(),
                'branches' => $this->branchOptions($user),
                'units' => self::UNITS,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function providerOptions(): array
    {
        return Provider::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function branchOptions(User $user): array
    {
        $query = Branch::query()->where('status', 'active')->orderBy('name');
        if ($user->hasRole('admin-sucursal') && $user->branch_id) {
            $query->where('id', $user->branch_id);
        }

        return $query->get(['id', 'name'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'nombre' => $b->name])
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

    private function cleanUnit(mixed $value): string
    {
        $unit = is_string($value) ? mb_strtolower(trim($value)) : '';

        return in_array($unit, self::UNITS, true) ? $unit : ($unit !== '' ? mb_substr($unit, 0, 10) : 'kg');
    }

    private function cleanDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
