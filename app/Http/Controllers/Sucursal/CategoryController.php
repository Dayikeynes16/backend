<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        $categories = Category::where('branch_id', $branchId)
            ->withCount('products')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sucursal/Categorias/Index', [
            'categories' => $categories,
            'filters' => $request->only('search'),
            'tenant' => app('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
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
        if ($category->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $category->update($validated);

        return back()->with('success', 'Categoria actualizada.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        $category->delete();

        return back()->with('success', 'Categoria eliminada.');
    }
}
