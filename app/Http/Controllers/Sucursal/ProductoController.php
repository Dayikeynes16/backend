<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
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
            ->withCount('presentations')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->sale_mode, fn ($q, $m) => $q->where('sale_mode', $m))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $categories = Category::where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Sucursal/Productos/Index', [
            'productos' => $productos,
            'categories' => $categories,
            'filters' => $request->only('search', 'category_id', 'sale_mode'),
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
            'sale_mode' => 'required|in:weight,presentation',
            'visibility' => 'required|in:public,restricted',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'presentations' => 'required_if:sale_mode,presentation|array|min:1',
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
            'unit_type' => $validated['sale_mode'] === 'weight' ? 'kg' : 'piece',
            'sale_mode' => $validated['sale_mode'],
            'visibility' => $validated['visibility'],
        ];

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->storePublicly('products');
        }

        $product = Product::create($data);

        if ($validated['sale_mode'] === 'presentation' && ! empty($validated['presentations'])) {
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
            'sale_mode' => 'required|in:weight,presentation',
            'visibility' => 'required|in:public,restricted',
            'status' => 'required|in:active,inactive',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'presentations' => 'required_if:sale_mode,presentation|array|min:1',
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
            'unit_type' => $validated['sale_mode'] === 'weight' ? 'kg' : 'piece',
            'visibility' => $validated['visibility'],
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
        if ($validated['sale_mode'] === 'presentation') {
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

    public function destroy(Product $producto): RedirectResponse
    {
        if ($producto->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        if ($producto->image_path) {
            Storage::delete($producto->image_path);
        }

        $producto->delete();

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto eliminado.');
    }
}
