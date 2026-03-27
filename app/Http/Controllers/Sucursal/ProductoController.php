<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->when($request->unit_type, fn ($q, $t) => $q->where('unit_type', $t))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
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
            'filters' => $request->only('search', 'unit_type', 'category_id'),
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category_id' => 'nullable|exists:categories,id',
            'price' => 'required|numeric|min:0.01',
            'cost_price' => 'nullable|numeric|min:0',
            'unit_type' => 'required|in:kg,piece,cut',
            'visibility' => 'required|in:public,restricted',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
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
            'unit_type' => $validated['unit_type'],
            'visibility' => $validated['visibility'],
        ];

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        Product::create($data);

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

        return Inertia::render('Sucursal/Productos/Edit', [
            'producto' => $producto,
            'categories' => $categories,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, Product $producto): RedirectResponse
    {
        if ($producto->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category_id' => 'nullable|exists:categories,id',
            'price' => 'required|numeric|min:0.01',
            'cost_price' => 'nullable|numeric|min:0',
            'unit_type' => 'required|in:kg,piece,cut',
            'visibility' => 'required|in:public,restricted',
            'status' => 'required|in:active,inactive',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
        ]);

        $data = collect($validated)->except('image')->toArray();

        if ($request->hasFile('image')) {
            if ($producto->image_path) {
                Storage::disk('public')->delete($producto->image_path);
            }
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        $producto->update($data);

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $producto): RedirectResponse
    {
        if ($producto->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        if ($producto->image_path) {
            Storage::disk('public')->delete($producto->image_path);
        }

        $producto->delete();

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto eliminado.');
    }
}
