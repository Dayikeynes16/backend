<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ],
        ], [
            'name.unique' => 'Ya existe una categoría de gastos con ese nombre.',
        ]);

        ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'Categoría creada.');
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $tenant = app('tenant');

        if ($category->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->ignore($category->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ],
            'status' => 'required|in:active,inactive',
        ], [
            'name.unique' => 'Ya existe otra categoría con ese nombre.',
        ]);

        $category->update($validated);

        return back()->with('success', 'Categoría actualizada.');
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        $tenant = app('tenant');

        if ($category->tenant_id !== $tenant->id) {
            abort(403);
        }

        $subCount = $category->subcategories()->count();
        if ($subCount > 0) {
            return back()->with('error', "No puedes eliminar esta categoría: tiene {$subCount} subcategoría".($subCount === 1 ? '' : 's').'. Desactívala o elimínalas primero.');
        }

        $category->delete();

        return back()->with('success', 'Categoría eliminada.');
    }
}
