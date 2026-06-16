<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\HandlesExpenseSubcategoryWrites;
use App\Http\Controllers\Controller;

/**
 * Escritura de subcategorías de gasto desde la sucursal sobre el catálogo
 * tenant-wide. Gateado con `branch.feature:branch_admin_expense_categories_enabled`.
 * El borrado queda en empresa.
 */
class ExpenseSubcategoryController extends Controller
{
    use HandlesExpenseSubcategoryWrites;
}
