<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Catálogo de productos de venta de la sucursal. `index` es la búsqueda de
 * apoyo (ambos roles: precios preferenciales, edición de items). La gestión
 * completa (manage/show/store/update/quickToggle/destroy + categorías) es de
 * admin-sucursal, paridad con Sucursal\ProductoController y CategoryController.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
            'with_presentations' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $search = trim((string) $request->input('search', ''));
        $withPresentations = $request->boolean('with_presentations');

        $products = Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $user->tenant_id)
            ->where('branch_id', $user->branch_id)
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->when($withPresentations, fn ($q) => $q->with(['presentations' => fn ($pq) => $pq->orderBy('sort_order')]))
            ->orderBy('name')
            ->limit(50)
            ->get($withPresentations
                ? ['id', 'name', 'price', 'unit_type', 'sale_mode']
                : ['id', 'name', 'price', 'unit_type']);

        return response()->json([
            'data' => $products->map(fn ($p) => array_merge([
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'unit_type' => $p->unit_type,
            ], $withPresentations ? [
                'sale_mode' => $p->sale_mode,
                'presentations' => $p->presentations->map(fn ($pr) => [
                    'id' => $pr->id,
                    'name' => $pr->name,
                    'content' => (float) $pr->content,
                    'unit' => $pr->unit,
                    'price' => (float) $pr->price,
                ])->values(),
            ] : []))->values(),
        ]);
    }

    /** Pantalla de gestión: lista paginada + filtros + categorías + stats. */
    public function manage(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $branchId = $user->branch_id;

        $search = trim((string) $request->input('search', ''));

        $products = Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('branch_id', $branchId)
            ->with(['category:id,name', 'presentations' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->withCount('presentations')
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->when($request->integer('category_id'), fn ($q, $cid) => $q->where('category_id', $cid))
            ->when($request->input('sale_mode'), fn ($q, $m) => $q->where('sale_mode', $m))
            ->when($request->input('status', 'all') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('name')
            ->paginate(20);

        $statsRow = Product::withoutGlobalScopes()->whereNull('deleted_at')->where('branch_id', $branchId)
            ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'active') AS active, COUNT(*) FILTER (WHERE status = 'inactive') AS inactive")
            ->toBase()->first();

        return response()->json([
            'data' => collect($products->items())->map(fn (Product $p) => $this->row($p))->values(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
            'stats' => [
                'total' => (int) ($statsRow->total ?? 0),
                'active' => (int) ($statsRow->active ?? 0),
                'inactive' => (int) ($statsRow->inactive ?? 0),
            ],
            'categories' => $this->categoriesFor($branchId, activeOnly: true),
            'category_rows' => $this->categoriesFor($branchId, activeOnly: false, withCounts: true),
        ]);
    }

    /** Detalle completo para edición (snapshot). */
    public function show(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $found = $this->findProduct($user, $product);
        $found->load(['category:id,name', 'presentations' => fn ($q) => $q->orderBy('sort_order')]);

        return response()->json(['data' => $this->row($found, full: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);

        $validated = $this->validateProduct($request, $user->branch_id);

        $product = DB::transaction(function () use ($validated, $request, $user) {
            $product = Product::create([
                'tenant_id' => $user->tenant_id,
                'branch_id' => $user->branch_id,
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'cost_price' => $validated['cost_price'] ?? null,
                'sale_mode' => $validated['sale_mode'],
                'unit_type' => $validated['sale_mode'] === 'presentation' ? 'piece' : 'kg',
                'visibility' => $validated['visibility'],
                'visible_online' => (bool) ($validated['visible_online'] ?? false),
                'image_path' => $request->hasFile('image')
                    ? $request->file('image')->storePublicly('products')
                    : null,
            ]);

            $this->syncPresentations($product, $validated);

            return $product;
        });

        return response()->json(['data' => $this->row($product->fresh()->load(['category:id,name', 'presentations']), full: true)], 201);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $found = $this->findProduct($user, $product);

        $validated = $this->validateProduct($request, $user->branch_id, withStatus: true);

        DB::transaction(function () use ($found, $validated, $request) {
            if ($request->hasFile('image')) {
                if ($found->image_path) {
                    Storage::disk('public')->delete($found->image_path);
                }
                $found->image_path = $request->file('image')->storePublicly('products');
            }

            $found->update([
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'cost_price' => $validated['cost_price'] ?? null,
                'sale_mode' => $validated['sale_mode'],
                'unit_type' => $validated['sale_mode'] === 'presentation' ? 'piece' : 'kg',
                'visibility' => $validated['visibility'],
                'visible_online' => (bool) ($validated['visible_online'] ?? false),
                'status' => $validated['status'],
                'image_path' => $found->image_path,
            ]);

            // Delete + recreate (paridad web; no upsert por id).
            $found->presentations()->delete();
            $this->syncPresentations($found, $validated);
        });

        return response()->json(['data' => $this->row($found->fresh()->load(['category:id,name', 'presentations']), full: true)]);
    }

    /** Toggle seguro de status y/o visible_online, sin el form completo. */
    public function quickToggle(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $found = $this->findProduct($user, $product);

        $validated = $request->validate([
            'status' => 'sometimes|in:active,inactive',
            'visible_online' => 'sometimes|boolean',
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'Sin cambios.'], 422);
        }

        $found->update($validated);

        return response()->json(['data' => $this->row($found->fresh()->load('category:id,name'))]);
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $found = $this->findProduct($user, $product);

        $hasSales = SaleItem::where('product_id', $found->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($hasSales) {
            return response()->json([
                'message' => 'No se puede eliminar un producto con ventas en los últimos 30 días. Desactívalo en su lugar.',
            ], 422);
        }

        $found->delete();

        return response()->json(['action' => 'deleted']);
    }

    // ── Categorías de venta ──────────────────────────────────────────────

    public function storeCategory(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->where(fn ($q) => $q->where('branch_id', $user->branch_id))],
        ], ['name.unique' => 'Ya existe una categoría con ese nombre en esta sucursal.']);

        $category = Category::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'name' => $validated['name'],
            'status' => 'active',
        ]);

        return response()->json(['data' => ['id' => $category->id, 'name' => $category->name, 'status' => 'active', 'products_count' => 0]], 201);
    }

    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $found = Category::withoutGlobalScopes()->where('branch_id', $user->branch_id)->findOrFail($category);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($found->id)->where(fn ($q) => $q->where('branch_id', $user->branch_id))],
            'status' => 'required|in:active,inactive',
        ], ['name.unique' => 'Ya existe una categoría con ese nombre en esta sucursal.']);

        $found->update($validated);

        return response()->json(['data' => ['id' => $found->id, 'name' => $found->name, 'status' => $found->status, 'products_count' => $found->products()->count()]]);
    }

    public function destroyCategory(Request $request, int $category): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $found = Category::withoutGlobalScopes()->where('branch_id', $user->branch_id)->findOrFail($category);

        $count = $found->products()->count();
        if ($count > 0) {
            return response()->json([
                'message' => "No puedes eliminar esta categoría: tiene {$count} producto(s) asignado(s). Reasígnalos primero.",
            ], 422);
        }

        $found->delete();

        return response()->json(['action' => 'deleted']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function ensureAdmin(User $user): void
    {
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede gestionar el catálogo.'
        );
    }

    private function findProduct(User $user, int $product): Product
    {
        return Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('branch_id', $user->branch_id)
            ->findOrFail($product);
    }

    /** @return array<int, array<string, mixed>> */
    private function categoriesFor(int $branchId, bool $activeOnly, bool $withCounts = false): array
    {
        return Category::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->when($activeOnly, fn ($q) => $q->where('status', 'active'))
            ->when($withCounts, fn ($q) => $q->withCount('products'))
            ->orderBy('name')
            ->get()
            ->map(fn (Category $c) => $withCounts
                ? ['id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'products_count' => (int) $c->products_count]
                : ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /** @param  array<string, mixed>  $validated */
    private function syncPresentations(Product $product, array $validated): void
    {
        if (! in_array($validated['sale_mode'], ['presentation', 'both'], true)) {
            return;
        }
        foreach ($validated['presentations'] ?? [] as $i => $p) {
            $product->presentations()->create([
                'name' => $p['name'],
                'content' => $p['content'],
                'unit' => $p['unit'],
                'price' => $p['price'],
                'sort_order' => $i,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validateProduct(Request $request, int $branchId, bool $withStatus = false): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('branch_id', $branchId)],
            'price' => 'required|numeric|min:0.01',
            'cost_price' => 'nullable|numeric|min:0',
            'sale_mode' => 'required|in:weight,presentation,both',
            'visibility' => 'required|in:public,restricted',
            'visible_online' => 'sometimes|boolean',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'presentations' => 'required_if:sale_mode,presentation|required_if:sale_mode,both|array|min:1',
            'presentations.*.name' => 'required|string|max:255',
            'presentations.*.content' => 'required|numeric|gt:0',
            'presentations.*.unit' => 'required|in:g,kg,ml,l,pieza',
            'presentations.*.price' => 'required|numeric|min:0.01',
        ];
        if ($withStatus) {
            $rules['status'] = 'required|in:active,inactive';
        }

        return $request->validate($rules, [
            'presentations.required_if' => 'Debes agregar al menos una presentación.',
            'presentations.min' => 'Debes agregar al menos una presentación.',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Product $p, bool $full = false): array
    {
        $base = [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'cost_price' => $p->cost_price !== null ? (float) $p->cost_price : null,
            'unit_type' => $p->unit_type,
            'sale_mode' => $p->sale_mode,
            'status' => $p->status,
            'visibility' => $p->visibility,
            'visible_online' => (bool) $p->visible_online,
            'category_id' => $p->category_id,
            'category_label' => $p->category?->name,
            'image_url' => $p->image_url,
            'presentations_count' => (int) ($p->presentations_count ?? 0),
        ];

        if (! $full) {
            return $base;
        }

        return array_merge($base, [
            'description' => $p->description,
            'presentations' => $p->relationLoaded('presentations')
                ? $p->presentations->map(fn ($pr) => [
                    'id' => $pr->id,
                    'name' => $pr->name,
                    'content' => (float) $pr->content,
                    'unit' => $pr->unit,
                    'price' => (float) $pr->price,
                ])->values()
                : [],
        ]);
    }
}
