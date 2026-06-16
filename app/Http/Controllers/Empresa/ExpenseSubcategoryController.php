<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Concerns\HandlesExpenseSubcategoryWrites;
use App\Http\Controllers\Controller;
use App\Models\ExpenseSubcategory;
use Illuminate\Http\RedirectResponse;

class ExpenseSubcategoryController extends Controller
{
    use HandlesExpenseSubcategoryWrites;

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
}
