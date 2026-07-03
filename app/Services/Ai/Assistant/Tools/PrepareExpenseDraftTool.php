<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\AiExpenseDraftService;
use App\Services\Ai\AiExpenseProposalParser;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;
use App\Services\Ai\ExpenseContextBuilder;
use Throwable;

/**
 * Prepara un BORRADOR de gasto para que el usuario lo confirme desde el chat.
 *
 * NO crea el Expense: persiste un `assistant_draft` (type=expense, status=ready)
 * y devuelve una tarjeta editable. La confirmación es una 2ª petición HTTP a
 * AssistantDraftController@confirm disparada por el botón "Confirmar gasto".
 *
 * - Texto: usa los parámetros que llenó el router y los normaliza.
 * - Recibo (imagen): extrae con visión reutilizando AiExpenseDraftService.
 */
class PrepareExpenseDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(
        private readonly AssistantDraftService $drafts,
        private readonly AiExpenseDraftService $expenseService,
        private readonly AiExpenseProposalParser $parser,
        private readonly ExpenseContextBuilder $contextBuilder,
    ) {}

    public function name(): string
    {
        return 'preparar_borrador_gasto';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de gasto (no lo registra) para que el usuario lo confirme. Úsala cuando el usuario quiere registrar/capturar un gasto por texto o adjuntando un recibo. Ejemplos: "registra gasolina 850 de hoy en efectivo", "pagué 1500 de luz por transferencia", o cuando adjunta la foto de un recibo.';
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
                'concepto' => ['type' => ['string', 'null'], 'description' => 'Descripción breve del gasto, p.ej. "Recibo de luz CFE".'],
                'monto' => ['type' => ['number', 'null'], 'description' => 'Monto en pesos. Déjalo null si no lo sabes; no lo inventes.'],
                'fecha' => ['type' => ['string', 'null'], 'description' => 'Fecha del gasto YYYY-MM-DD. Resuelve "hoy"/"ayer" con la fecha del contexto.'],
                'metodo_pago' => ['type' => ['string', 'null'], 'enum' => ['cash', 'card', 'transfer'], 'description' => 'Método de pago si el usuario lo indica.'],
                'categoria_nombre' => ['type' => ['string', 'null'], 'description' => 'Nombre de la categoría de gasto si se puede inferir del catálogo.'],
                'subcategoria_nombre' => ['type' => ['string', 'null'], 'description' => 'Nombre de la subcategoría de gasto si se puede inferir del catálogo.'],
                'branch_name' => ['type' => ['string', 'null'], 'description' => 'Nombre de la sucursal del gasto. Para admin-sucursal se ignora (se usa la suya).'],
                'descripcion' => ['type' => ['string', 'null'], 'description' => 'Detalle libre opcional.'],
            ],
            'required' => ['concepto', 'monto', 'fecha', 'metodo_pago', 'categoria_nombre', 'subcategoria_nombre', 'branch_name', 'descripcion'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $tenant = app('tenant');
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $subId = $this->resolveSubcategoryId(
            $tenant->id,
            $params['subcategoria_nombre'] ?? null,
            $params['categoria_nombre'] ?? null,
        );

        return [
            'concepto' => $params['concepto'] ?? null,
            'monto' => $params['monto'] ?? null,
            'fecha' => $params['fecha'] ?? null,
            'metodo_pago' => $params['metodo_pago'] ?? null,
            'categoria_nombre' => $params['categoria_nombre'] ?? null,
            'subcategoria_nombre' => $params['subcategoria_nombre'] ?? null,
            'descripcion' => $params['descripcion'] ?? null,
            'expense_subcategory_id' => $subId,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');
        $images = $context->images();

        $draft = $this->drafts->create(
            AssistantDraftType::Expense,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
            images: $images,
        );

        try {
            if ($images !== []) {
                $extracted = $this->expenseService->extractProposal(
                    $tenant,
                    $user,
                    (string) $context->userMessage->content,
                    $draft->fresh()->attachment_paths ?? [],
                );
                $proposal = $extracted['proposal'];
                $telemetry = $extracted['telemetry'];
            } else {
                $proposal = $this->parser->parse($this->paramsToRaw($params), $tenant);
                $telemetry = [];
            }
        } catch (Throwable $e) {
            $this->drafts->markFailed($draft, $e->getMessage());
            throw $e;
        }

        $proposal = $this->applyBranch($proposal, $user, $params);
        $proposal = $this->finalize($proposal);

        $this->drafts->markReady($draft, $proposal, $telemetry);

        $data = $this->buildCard($draft->fresh(), $proposal, $user);

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de gasto. Está pendiente de tu confirmación.',
            params: $params,
            // Al modelo NO le devolvemos toda la propuesta: sólo que quedó lista.
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'expense',
                'status' => 'prepared',
                'missing_fields' => $proposal['campos_faltantes'] ?? [],
                'summary' => 'Borrador de gasto preparado. Espera a que el usuario lo confirme con el botón; tú no puedes confirmarlo.',
            ],
        );
    }

    /**
     * Construye el array crudo que espera el parser a partir de los params
     * validados (camino de sólo texto).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function paramsToRaw(array $params): array
    {
        return [
            'concepto' => $params['concepto'] ?? null,
            'monto' => $params['monto'] ?? null,
            'fecha' => $params['fecha'] ?? null,
            'expense_subcategory_id' => $params['expense_subcategory_id'] ?? null,
            'categoria_nombre' => $params['categoria_nombre'] ?? null,
            'subcategoria_nombre' => $params['subcategoria_nombre'] ?? null,
            'metodo_pago' => $params['metodo_pago'] ?? null,
            'branch_id' => null, // la sucursal se fuerza en applyBranch, no se confía al modelo
            'descripcion' => $params['descripcion'] ?? null,
            'confianza' => 'media',
        ];
    }

    /**
     * Fuerza la sucursal: admin-sucursal siempre la suya; admin-empresa la que
     * nombró (si existe). Nunca se confía en la sucursal que devuelva el modelo.
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function applyBranch(array $proposal, User $user, array $params): array
    {
        $proposal['branch_id'] = $params['branch_id'] ?? null;
        $proposal['branch_name'] = $params['branch_name'] ?? null;

        return $proposal;
    }

    /**
     * Aplica valores por defecto y recalcula los campos que faltan para poder
     * confirmar el gasto.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function finalize(array $proposal): array
    {
        if (empty($proposal['fecha'])) {
            $proposal['fecha'] = now()->toDateString();
        }

        $missing = [];
        if (empty($proposal['monto'])) {
            $missing[] = 'monto';
        }
        if (empty($proposal['concepto'])) {
            $missing[] = 'concepto';
        }
        if (empty($proposal['expense_subcategory_id'])) {
            $missing[] = 'subcategoría';
        }
        if (empty($proposal['branch_id'])) {
            $missing[] = 'sucursal';
        }
        $proposal['campos_faltantes'] = $missing;

        return $proposal;
    }

    /**
     * Datos de la tarjeta editable del chat. Incluye las opciones de catálogo
     * (sucursales/subcategorías/métodos) para que la tarjeta sea autosuficiente.
     * Las cifras salen de aquí (JSON), no del texto del modelo.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function buildCard(AssistantDraft $draft, array $proposal, User $user): array
    {
        $context = $this->contextBuilder->build(app('tenant'), $user);

        $subcategories = [];
        foreach ($context['categorias'] ?? [] as $cat) {
            foreach ($cat['subcategorias'] ?? [] as $sub) {
                $subcategories[] = [
                    'id' => $sub['id'],
                    'name' => $sub['nombre'],
                    'category_name' => $cat['nombre'],
                ];
            }
        }

        return [
            'draft_id' => $draft->id,
            'draft_type' => 'expense',
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'preview' => [
                'concepto' => $proposal['concepto'] ?? null,
                'monto' => $proposal['monto'] ?? null,
                'fecha' => $proposal['fecha'] ?? null,
                'metodo_pago' => $proposal['metodo_pago'] ?? null,
                'expense_subcategory_id' => $proposal['expense_subcategory_id'] ?? null,
                'subcategoria_nombre' => $proposal['subcategoria_nombre'] ?? null,
                'categoria_nombre' => $proposal['categoria_nombre'] ?? null,
                'branch_id' => $proposal['branch_id'] ?? null,
                'branch_name' => $proposal['branch_name'] ?? null,
                'descripcion' => $proposal['descripcion'] ?? null,
            ],
            'missing_fields' => $proposal['campos_faltantes'] ?? [],
            'warnings' => $proposal['alertas'] ?? [],
            'confianza' => $proposal['confianza'] ?? null,
            'attachments' => array_map(fn ($a) => [
                'original_name' => $a['original_name'] ?? null,
                'mime_type' => $a['mime_type'] ?? null,
            ], $draft->attachment_paths ?? []),
            'options' => [
                'branches' => $context['sucursales'] ?? [],
                'subcategories' => $subcategories,
                'payment_methods' => $context['metodos_pago'] ?? [],
            ],
        ];
    }

    private function resolveSubcategoryId(int $tenantId, ?string $subName, ?string $catName): ?int
    {
        $name = trim((string) $subName);
        if ($name === '') {
            return null;
        }

        return ExpenseSubcategory::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($catName, function ($q, $cat) use ($tenantId) {
                $q->whereHas('category', fn ($cq) => $cq
                    ->where('tenant_id', $tenantId)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $cat)]));
            })
            ->value('id');
    }
}
