<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\HandlesExpenseCategoryWrites;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiCategoryDraftService;

/**
 * Escritura de categorías de gasto desde la sucursal. El catálogo es tenant-wide
 * (compartido con empresa y demás sucursales); este controlador solo expone
 * crear/editar y consumir borradores de IA. El acceso se gatea con el middleware
 * `branch.feature:branch_admin_expense_categories_enabled`. El borrado queda en
 * empresa.
 */
class ExpenseCategoryController extends Controller
{
    use HandlesExpenseCategoryWrites;

    public function __construct(
        protected readonly AiCategoryDraftService $aiDrafts,
    ) {}
}
