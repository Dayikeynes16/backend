<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Legacy route. La gestión de categorías vive ahora dentro de Productos
     * como tab. Mantenemos la URL para no romper bookmarks/onboarding y
     * redirigimos al tab correcto.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('sucursal.productos.index', [
            app('tenant')->slug,
            'tab' => 'categorias',
        ], 301);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->where(fn ($q) => $q->where('branch_id', $user->branch_id)),
            ],
        ], [
            'name.unique' => 'Ya existe una categoría con ese nombre en esta sucursal.',
        ]);

        Category::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            ...$validated,
        ]);

        return back()->with('success', 'Categoria creada.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $user = Auth::user();

        if ($category->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->ignore($category->id)
                    ->where(fn ($q) => $q->where('branch_id', $user->branch_id)),
            ],
            'status' => 'required|in:active,inactive',
        ], [
            'name.unique' => 'Ya existe una categoría con ese nombre en esta sucursal.',
        ]);

        $category->update($validated);

        return back()->with('success', 'Categoria actualizada.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        // Bloqueo duro: si la categoría tiene productos asociados, no se
        // elimina. El admin debe reasignar o eliminar los productos primero.
        // Evita huérfanos silenciosos por la FK nullable en products.
        $count = $category->products()->count();
        if ($count > 0) {
            return back()->with('error', "No puedes eliminar esta categoría: tiene {$count} producto".($count === 1 ? '' : 's').' asignado'.($count === 1 ? '' : 's').'. Reasigna o elimina los productos primero.');
        }

        $category->delete();

        return back()->with('success', 'Categoria eliminada.');
    }
}
