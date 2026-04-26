<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        $productos = Product::query()
            ->where('branch_id', $branchId)
            ->with('category:id,name')
            ->with(['presentations' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->withCount('presentations')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->sale_mode, fn ($q, $m) => $q->where('sale_mode', $m))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // categoriesForFilter: lista plana de categorías activas, usada por el
        // dropdown del filtro de Productos (igual que antes).
        // categoriesForTab: lista completa con products_count para el tab de
        // Categorías. Pocas categorías esperadas (3-10) → sin paginar.
        $categoriesForFilter = Category::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        $categoriesForTab = Category::where('branch_id', $branchId)
            ->withCount('products')
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        return Inertia::render('Sucursal/Productos/Index', [
            'productos' => $productos,
            'categories' => $categoriesForFilter,
            'categoriesForTab' => $categoriesForTab,
            'filters' => $request->only('search', 'category_id', 'sale_mode'),
            'tab' => $request->input('tab', 'productos'),
            'tenant' => app('tenant'),
        ]);
    }

    public function create(): Response
    {
        $branchId = Auth::user()->branch_id;

        $categories = Category::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Sucursal/Productos/Create', [
            'categories' => $categories,
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branchId = Auth::user()->branch_id;

        $validated = $request->validate([
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
        ], [
            'presentations.required_if' => 'Debes agregar al menos una presentacion.',
            'presentations.min' => 'Debes agregar al menos una presentacion.',
        ]);

        $user = Auth::user();

        $data = [
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'price' => $validated['price'],
            'cost_price' => $validated['cost_price'] ?? null,
            'unit_type' => $validated['sale_mode'] === 'presentation' ? 'piece' : 'kg',
            'sale_mode' => $validated['sale_mode'],
            'visibility' => $validated['visibility'],
            'visible_online' => (bool) ($validated['visible_online'] ?? false),
        ];

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->storePublicly('products');
        }

        $product = Product::create($data);

        if (in_array($validated['sale_mode'], ['presentation', 'both']) && ! empty($validated['presentations'])) {
            foreach ($validated['presentations'] as $i => $p) {
                $product->presentations()->create([
                    'name' => $p['name'],
                    'content' => $p['content'],
                    'unit' => $p['unit'],
                    'price' => $p['price'],
                    'sort_order' => $i,
                ]);
            }
        }

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto creado.');
    }

    public function edit(Product $producto): Response
    {
        $branchId = Auth::user()->branch_id;

        if ($producto->branch_id !== $branchId) {
            abort(403);
        }

        $categories = Category::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        $producto->load('presentations');

        return Inertia::render('Sucursal/Productos/Edit', [
            'producto' => $producto,
            'categories' => $categories,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, Product $producto): RedirectResponse
    {
        $branchId = Auth::user()->branch_id;

        if ($producto->branch_id !== $branchId) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('branch_id', $branchId)],
            'price' => 'required|numeric|min:0.01',
            'cost_price' => 'nullable|numeric|min:0',
            'sale_mode' => 'required|in:weight,presentation,both',
            'visibility' => 'required|in:public,restricted',
            'visible_online' => 'sometimes|boolean',
            'status' => 'required|in:active,inactive',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'presentations' => 'required_if:sale_mode,presentation|required_if:sale_mode,both|array|min:1',
            'presentations.*.name' => 'required|string|max:255',
            'presentations.*.content' => 'required|numeric|gt:0',
            'presentations.*.unit' => 'required|in:g,kg,ml,l,pieza',
            'presentations.*.price' => 'required|numeric|min:0.01',
        ], [
            'presentations.required_if' => 'Debes agregar al menos una presentacion.',
            'presentations.min' => 'Debes agregar al menos una presentacion.',
        ]);

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'price' => $validated['price'],
            'cost_price' => $validated['cost_price'] ?? null,
            'sale_mode' => $validated['sale_mode'],
            'unit_type' => $validated['sale_mode'] === 'presentation' ? 'piece' : 'kg',
            'visibility' => $validated['visibility'],
            'visible_online' => (bool) ($validated['visible_online'] ?? false),
            'status' => $validated['status'],
        ];

        if ($request->hasFile('image')) {
            if ($producto->image_path) {
                Storage::delete($producto->image_path);
            }
            $data['image_path'] = $request->file('image')->storePublicly('products');
        }

        $producto->update($data);

        // Sync presentations
        if (in_array($validated['sale_mode'], ['presentation', 'both'])) {
            $producto->presentations()->delete();
            foreach (($validated['presentations'] ?? []) as $i => $p) {
                $producto->presentations()->create([
                    'name' => $p['name'],
                    'content' => $p['content'],
                    'unit' => $p['unit'],
                    'price' => $p['price'],
                    'sort_order' => $i,
                ]);
            }
        } else {
            $producto->presentations()->delete();
        }

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto actualizado.');
    }

    /**
     * Toggle rápido desde el modal de detalle. Solo permite cambiar campos
     * binarios seguros (status, visible_online) sin pasar por el form
     * completo de update (que requiere imagen, presentaciones, etc.).
     */
    public function quickToggle(Request $request, Product $producto): RedirectResponse
    {
        if ($producto->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'visible_online' => ['sometimes', 'boolean'],
        ]);

        if (empty($validated)) {
            return back()->with('error', 'Sin cambios.');
        }

        $producto->update($validated);

        return back()->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $producto): RedirectResponse
    {
        if ($producto->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        // Prevent deletion if product has sales in the last 30 days
        $hasSales = SaleItem::where('product_id', $producto->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($hasSales) {
            return back()->with('error', 'No se puede eliminar un producto con ventas en los ultimos 30 dias. Desactivalo en su lugar.');
        }

        $producto->delete();

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto eliminado.');
    }
}
