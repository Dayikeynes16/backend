<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExpenseSubcategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'expense_category_id' => [
                'required',
                Rule::exists('expense_categories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
            'includes' => 'nullable|array|max:15',
            'includes.*' => 'nullable|string|max:80',
            'excludes' => 'nullable|array|max:15',
            'excludes.*' => 'nullable|string|max:80',
        ], [
            'expense_category_id.exists' => 'Categoría inválida.',
        ]);

        $duplicate = ExpenseSubcategory::where('expense_category_id', $validated['expense_category_id'])
            ->where('name', $validated['name'])
            ->exists();
        if ($duplicate) {
            return back()->withErrors(['name' => 'Ya existe esa subcategoría dentro de la categoría.']);
        }

        $category = ExpenseCategory::findOrFail($validated['expense_category_id']);

        ExpenseSubcategory::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'aliases' => $this->normalizeAliases($validated['aliases'] ?? null),
            'includes' => $this->normalizeAliases($validated['includes'] ?? null),
            'excludes' => $this->normalizeAliases($validated['excludes'] ?? null),
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'Subcategoría creada.');
    }

    public function update(Request $request, ExpenseSubcategory $subcategory): RedirectResponse
    {
        $tenant = app('tenant');

        if ($subcategory->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
            'includes' => 'nullable|array|max:15',
            'includes.*' => 'nullable|string|max:80',
            'excludes' => 'nullable|array|max:15',
            'excludes.*' => 'nullable|string|max:80',
            'status' => 'required|in:active,inactive',
        ]);

        $duplicate = ExpenseSubcategory::where('expense_category_id', $subcategory->expense_category_id)
            ->where('name', $validated['name'])
            ->where('id', '!=', $subcategory->id)
            ->exists();
        if ($duplicate) {
            return back()->withErrors(['name' => 'Ya existe otra subcategoría con ese nombre en la categoría.']);
        }

        $subcategory->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'aliases' => $this->normalizeAliases($validated['aliases'] ?? null),
            'includes' => $this->normalizeAliases($validated['includes'] ?? null),
            'excludes' => $this->normalizeAliases($validated['excludes'] ?? null),
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Subcategoría actualizada.');
    }

    public function destroy(ExpenseSubcategory $subcategory): RedirectResponse
    {
        $tenant = app('tenant');

        if ($subcategory->tenant_id !== $tenant->id) {
            abort(403);
        }

        $expensesCount = $subcategory->expenses()->withTrashed()->count();
        if ($expensesCount > 0) {
            return back()->with('error', "No puedes eliminar esta subcategoría: tiene {$expensesCount} gasto".($expensesCount === 1 ? '' : 's').' asociado'.($expensesCount === 1 ? '' : 's').'. Desactívala o reasigna los gastos.');
        }

        $subcategory->delete();

        return back()->with('success', 'Subcategoría eliminada.');
    }

    /**
     * @param  array<int, string>|null  $aliases
     * @return array<int, string>|null
     */
    private function normalizeAliases(?array $aliases): ?array
    {
        if (! $aliases) {
            return null;
        }

        $cleaned = collect($aliases)
            ->map(fn ($a) => trim((string) $a))
            ->filter()
            ->unique(fn ($a) => mb_strtolower($a))
            ->values()
            ->all();

        return $cleaned === [] ? null : $cleaned;
    }
}
