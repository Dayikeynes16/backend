<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Enums\AssistantDraftType;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AbstractPrepareDraftTool;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\ToolResult;

/**
 * Prepara un BORRADOR de cambio de PRECIO BASE de un producto (D7: solo
 * admin-sucursal, solo el campo `price`; presentaciones fuera de alcance).
 * La card muestra precio actual → nuevo y advierte si queda por debajo del
 * costo o el cambio es mayor al 50%.
 */
class PrepareProductPriceDraftTool extends AbstractPrepareDraftTool
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function name(): string
    {
        return 'preparar_cambio_precio';
    }

    public function description(): string
    {
        return 'Prepara un BORRADOR de cambio del precio base de un producto de la sucursal (no lo aplica; el usuario debe confirmarlo). Ejemplos: "sube el bistec a 240", "cambia el precio del kilo de molida a $180".';
    }

    public function rolesAllowed(): array
    {
        return ['admin-sucursal'];
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'product_name' => ['type' => ['string', 'null'], 'description' => 'Nombre del producto.'],
                'new_price' => ['type' => ['number', 'null'], 'description' => 'Nuevo precio base en pesos.'],
            ],
            'required' => ['product_name', 'new_price'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $target = $this->resolveProduct($user, $params['product_name'] ?? null);

        return [
            'product_id' => $target['product_id'],
            'product' => $target['product'],
            'product_candidates' => $target['candidates'],
            'product_name' => $this->clean($params['product_name'] ?? null, 160),
            'new_price' => is_numeric($params['new_price'] ?? null) ? round((float) $params['new_price'], 2) : null,
        ];
    }

    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult
    {
        $tenant = app('tenant');

        $draft = $this->drafts->create(
            AssistantDraftType::PriceChange,
            $tenant,
            $user,
            $context,
            originalInput: ['text' => (string) $context->userMessage->content],
        );

        $missing = [];
        if (empty($params['product_id'])) {
            $missing[] = 'producto';
        }
        if (empty($params['new_price']) || $params['new_price'] <= 0) {
            $missing[] = 'nuevo precio';
        }

        $alerts = [];
        $product = $params['product'];
        if ($product && ! empty($params['new_price']) && $params['new_price'] > 0) {
            $current = (float) $product['current_price'];
            $cost = $product['cost_price'] !== null ? (float) $product['cost_price'] : null;

            if ($cost !== null && $params['new_price'] < $cost) {
                $alerts[] = 'El nuevo precio ($'.number_format($params['new_price'], 2).') queda POR DEBAJO del costo ($'.number_format($cost, 2).').';
            }
            if ($current > 0 && abs($params['new_price'] - $current) / $current > 0.5) {
                $alerts[] = 'El cambio es mayor al 50% respecto al precio actual ($'.number_format($current, 2).') — verifica que sea correcto.';
            }
        }

        $proposal = array_merge($params, [
            'campos_faltantes' => $missing,
            'alertas' => $alerts,
        ]);

        $this->drafts->markReady($draft, $proposal);

        $data = [
            'draft_id' => $draft->fresh()->id,
            'draft_type' => 'price_change',
            'status' => $draft->fresh()->status->value,
            'expires_at' => $draft->fresh()->expires_at?->toIso8601String(),
            'preview' => [
                'product_id' => $params['product_id'],
                'product' => $params['product'],
                'new_price' => $params['new_price'],
            ],
            'missing_fields' => $missing,
            'warnings' => $alerts,
            'options' => [
                'products' => $this->productOptions($user, $params['product_candidates']),
            ],
        ];

        return new ToolResult(
            kind: 'assistant_draft',
            data: $data,
            summary: 'Preparé un borrador de cambio de precio. Está pendiente de tu confirmación.',
            params: $params,
            modelPayload: [
                'kind' => 'assistant_draft',
                'draft_type' => 'price_change',
                'status' => 'prepared',
                'missing_fields' => $missing,
                'summary' => 'Borrador de cambio de precio preparado. Espera a que el usuario lo confirme con el botón; tú no puedes aplicar precios.',
            ],
        );
    }

    /**
     * Resuelve el producto por nombre dentro de la sucursal del usuario:
     * exacto → parcial; un único match = resuelto, varios = candidatos.
     *
     * @return array{product_id: int|null, product: array<string, mixed>|null, candidates: array<int, array<string, mixed>>}
     */
    private function resolveProduct(User $user, ?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['product_id' => null, 'product' => null, 'candidates' => []];
        }

        $pool = $this->productBase($user)->orderBy('name')->limit(500)->get();

        $result = $this->fuzzyMatchByName($pool, $name, fn (Product $p) => $p->name);

        if ($result['match']) {
            return ['product_id' => $result['match']->id, 'product' => $this->productInfo($result['match']), 'candidates' => []];
        }

        return [
            'product_id' => null,
            'product' => null,
            'candidates' => array_map(fn (Product $p) => $this->productInfo($p), $result['candidates']),
        ];
    }

    private function productBase(User $user)
    {
        return Product::query()
            ->where('branch_id', $user->branch_id)
            ->where('status', 'active');
    }

    /**
     * @return array<string, mixed>
     */
    private function productInfo(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'current_price' => (float) $p->price,
            'cost_price' => $p->cost_price !== null ? (float) $p->cost_price : null,
            'unit_type' => $p->unit_type,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function productOptions(User $user, array $candidates): array
    {
        if (! empty($candidates)) {
            return $candidates;
        }

        return $this->productBase($user)
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (Product $p) => $this->productInfo($p))
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
