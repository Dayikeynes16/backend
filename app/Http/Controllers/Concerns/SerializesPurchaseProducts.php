<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AuditEvent;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lectura del catálogo de productos de compra: payload del index (lista +
 * filtros + categorías + resumen) e historial bajo demanda. Compartido por los
 * controladores de empresa y sucursal para no duplicar la serialización.
 */
trait SerializesPurchaseProducts
{
    /**
     * Datos del index. La columna "última edición" se resuelve con una sola
     * query agregada a `audit_logs` (sin N+1).
     *
     * @return array<string, mixed>
     */
    protected function purchaseProductIndexData(Request $request): array
    {
        $search = trim((string) $request->input('q', ''));
        $categoryFilter = $request->input('category');
        $statusFilter = $request->input('status', 'active');

        $products = PurchaseProduct::query()
            ->with('category:id,name')
            ->withCount('purchaseItems')
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when($categoryFilter, fn ($q) => $q->where('purchase_product_category_id', $categoryFilter))
            ->when($statusFilter !== 'all', fn ($q) => $q->where('status', $statusFilter))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $lastEdited = $this->lastEditedMap($products->getCollection()->pluck('id')->all());
        $products->through(fn (PurchaseProduct $p) => $this->serializePurchaseProduct($p, $lastEdited));

        return [
            'products' => $products,
            'filters' => ['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter],
            'categories' => PurchaseProductCategory::where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (PurchaseProductCategory $c) => ['value' => $c->id, 'label' => $c->name])->all(),
            'categoryRows' => PurchaseProductCategory::withCount('products')->orderBy('name')->get()
                ->map(fn (PurchaseProductCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'status' => $c->status,
                    'products_count' => (int) $c->products_count,
                ])->all(),
            'stats' => $this->purchaseProductStats(),
        ];
    }

    /**
     * Historial de cambios (quién/cuándo) de un producto, más reciente primero.
     */
    protected function purchaseProductHistory(PurchaseProduct $producto_compra): JsonResponse
    {
        if ($producto_compra->tenant_id !== app('tenant')->id) {
            abort(403);
        }

        $history = $producto_compra->history()->with('user:id,name')->get()->map(fn ($h) => [
            'event' => $h->event instanceof AuditEvent ? $h->event->value : $h->event,
            'user_name' => $h->user?->name,
            'created_at' => $h->created_at?->toIso8601String(),
            'changes' => $h->changes,
        ])->all();

        return response()->json([
            'product' => ['id' => $producto_compra->id, 'name' => $producto_compra->name],
            'history' => $history,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePurchaseProduct(PurchaseProduct $p, array $lastEdited): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'unit' => $p->unit,
            'category_id' => $p->purchase_product_category_id,
            'category_label' => $p->category?->name,
            'status' => $p->status,
            'purchase_items_count' => (int) ($p->purchase_items_count ?? 0),
            'last_edited' => $lastEdited[$p->id] ?? null,
        ];
    }

    /**
     * Resumen del catálogo completo del tenant (independiente de los filtros).
     *
     * @return array<string, int>
     */
    private function purchaseProductStats(): array
    {
        $row = PurchaseProduct::query()
            ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'active') AS active, COUNT(*) FILTER (WHERE status = 'inactive') AS inactive, COUNT(*) FILTER (WHERE purchase_product_category_id IS NULL) AS uncategorized")
            ->toBase()
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
            'uncategorized' => (int) ($row->uncategorized ?? 0),
        ];
    }

    /**
     * Último registro de auditoría por producto: {id => {by, at}}. Una sola
     * query (latest por auditable_id vía MAX(id)).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{by: ?string, at: ?string}>
     */
    private function lastEditedMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $type = (new PurchaseProduct)->getMorphClass();

        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.tenant_id', app('tenant')->id)
            ->where('a.auditable_type', $type)
            ->whereIn('a.auditable_id', $ids)
            ->whereIn('a.id', function ($sub) use ($ids, $type) {
                $sub->selectRaw('MAX(id)')
                    ->from('audit_logs')
                    ->where('auditable_type', $type)
                    ->whereIn('auditable_id', $ids)
                    ->groupBy('auditable_id');
            })
            ->get(['a.auditable_id', 'a.created_at', 'u.name as user_name']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->auditable_id] = [
                'by' => $row->user_name,
                'at' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ];
        }

        return $map;
    }
}
