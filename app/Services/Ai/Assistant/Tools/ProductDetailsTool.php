<?php

namespace App\Services\Ai\Assistant\Tools;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;

/**
 * Consulta detalles del catálogo de productos. Devuelve precio, costo, unidad,
 * categoría, presentaciones y estatus de los productos que coincidan con el
 * filtro (nombre y/o categoría).
 *
 * Para admin-sucursal: scopea por su `branch_id`. Para admin-empresa: por
 * sucursal (si especifica) o todas las sucursales del tenant.
 */
class ProductDetailsTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'consultar_productos';
    }

    public function description(): string
    {
        return 'Devuelve detalles del catálogo: precio, costo, unidad, categoría y presentaciones. Usar para "¿cuánto cuesta el kilo de pulpa?", "¿qué productos hay en la categoría carne?", "muéstrame el detalle del bistec".';
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
                'name_query' => [
                    'type' => ['string', 'null'],
                    'description' => 'Búsqueda parcial por nombre del producto (case-insensitive). Null para no filtrar por nombre.',
                ],
                'category_name' => [
                    'type' => ['string', 'null'],
                    'description' => 'Nombre exacto de la categoría a filtrar (case-insensitive). Null para no filtrar.',
                ],
                'branch_name' => [
                    'type' => ['string', 'null'],
                    'description' => 'Nombre de la sucursal. Para admin-empresa: si es null devuelve productos de todas. Para admin-sucursal: se ignora y se fuerza la suya.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                    'description' => 'Cuántos productos devolver (default 10).',
                ],
            ],
            'required' => ['name_query', 'category_name', 'branch_name', 'limit'],
        ];
    }

    public function validate(User $user, array $params): array
    {
        $branch = $this->resolveBranch($user, $params['branch_name'] ?? null);
        $limit = (int) ($params['limit'] ?? 10);
        $limit = max(1, min(20, $limit));

        $nameQuery = trim((string) ($params['name_query'] ?? ''));
        $categoryName = trim((string) ($params['category_name'] ?? ''));

        return [
            'name_query' => $nameQuery !== '' ? $nameQuery : null,
            'category_name' => $categoryName !== '' ? $categoryName : null,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'limit' => $limit,
        ];
    }

    public function execute(User $user, array $params): ToolResult
    {
        // TenantScope ya filtra por tenant. Branch lo agregamos si el resolver
        // devolvió uno (admin-sucursal forzosamente, admin-empresa si pidió).
        $query = Product::query()
            ->with(['category:id,name', 'branch:id,name', 'presentations:id,product_id,name,content,unit,price'])
            ->where('status', 'active')
            ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']));

        if ($params['name_query'] !== null) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($params['name_query']).'%']);
        }

        if ($params['category_name'] !== null) {
            $category = Category::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($params['category_name'])])
                ->when($params['branch_id'], fn ($q) => $q->where('branch_id', $params['branch_id']))
                ->first();

            if ($category) {
                $query->where('category_id', $category->id);
            } else {
                // Categoría no existe: devolvemos vacío con un alerta.
                return new ToolResult('product_details', [
                    'branch_name' => $params['branch_name'],
                    'name_query' => $params['name_query'],
                    'category_name' => $params['category_name'],
                    'category_found' => false,
                    'products' => [],
                ], "No encontré una categoría llamada \"{$params['category_name']}\".", $params);
            }
        }

        $products = $query
            ->orderBy('name')
            ->limit($params['limit'])
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'category' => $p->category?->name,
                'branch' => $p->branch?->name,
                'price' => (float) $p->price,
                'cost_price' => (float) $p->cost_price,
                'unit_type' => $p->unit_type,
                'sale_mode' => $p->sale_mode,
                'presentations' => $p->presentations->map(fn ($pr) => [
                    'name' => $pr->name,
                    'content' => (float) $pr->content,
                    'unit' => $pr->unit,
                    'price' => (float) $pr->price,
                ])->all(),
            ])
            ->values()
            ->all();

        $branchLabel = $params['branch_name'] ?? 'todas las sucursales';
        $count = count($products);

        if ($count === 0) {
            $summary = sprintf('No encontré productos para "%s" en %s.', $params['name_query'] ?? $params['category_name'] ?? 'el catálogo', $branchLabel);
        } else {
            $first = $products[0];
            $summary = $count === 1
                ? sprintf('%s cuesta $%s por %s.', $first['name'], number_format($first['price'], 2), $first['unit_type'])
                : sprintf('Encontré %d productos en %s.', $count, $branchLabel);
        }

        return new ToolResult('product_details', [
            'branch_name' => $params['branch_name'],
            'name_query' => $params['name_query'],
            'category_name' => $params['category_name'],
            'category_found' => true,
            'products' => $products,
        ], $summary, $params);
    }
}
