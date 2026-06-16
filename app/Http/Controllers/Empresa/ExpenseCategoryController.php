<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Concerns\HandlesExpenseCategoryWrites;
use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Services\Ai\AiCategoryDraftService;
use Illuminate\Http\RedirectResponse;

class ExpenseCategoryController extends Controller
{
    use HandlesExpenseCategoryWrites;

    public function __construct(
        protected readonly AiCategoryDraftService $aiDrafts,
    ) {}

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
