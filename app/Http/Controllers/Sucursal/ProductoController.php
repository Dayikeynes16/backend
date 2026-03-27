<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        $productos = Product::query()
            ->where('branch_id', $branchId)
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->when($request->unit_type, fn ($q, $t) => $q->where('unit_type', $t))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sucursal/Productos/Index', [
            'productos' => $productos,
            'filters' => $request->only('search', 'unit_type'),
            'tenant' => app('tenant'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sucursal/Productos/Create', [
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0.01',
            'unit_type' => 'required|in:kg,piece,cut',
        ]);

        $user = Auth::user();
        $validated['tenant_id'] = $user->tenant_id;
        $validated['branch_id'] = $user->branch_id;

        Product::create($validated);

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto creado exitosamente.');
    }

    public function edit(Product $producto): Response
    {
        return Inertia::render('Sucursal/Productos/Edit', [
            'producto' => $producto,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, Product $producto): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0.01',
            'unit_type' => 'required|in:kg,piece,cut',
            'status' => 'required|in:active,inactive',
        ]);

        $producto->update($validated);

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto actualizado exitosamente.');
    }

    public function destroy(Product $producto): RedirectResponse
    {
        $producto->delete();

        return redirect()->route('sucursal.productos.index', app('tenant')->slug)
            ->with('success', 'Producto eliminado exitosamente.');
    }
}
