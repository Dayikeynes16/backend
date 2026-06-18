<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\HandlesPurchaseProductCategoryWrites;
use App\Http\Controllers\Concerns\HandlesPurchaseProductWrites;
use App\Http\Controllers\Concerns\SerializesPurchaseProducts;
use App\Http\Controllers\Controller;
use App\Models\PurchaseProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de productos de compra y sus categorías para admin-sucursal. El
 * catálogo es tenant-wide; el acceso completo (incl. lectura) se concede sólo
 * cuando la empresa habilita el toggle `branch_admin_purchase_products_enabled`
 * de su sucursal — todas las rutas de este controlador se gatean con el
 * middleware `branch.feature`. Puede crear/editar/activar-desactivar productos
 * y categorías, pero NO eliminar (el destroy queda en empresa).
 */
class PurchaseProductController extends Controller
{
    use HandlesPurchaseProductCategoryWrites;
    use HandlesPurchaseProductWrites;
    use SerializesPurchaseProducts;

    public function index(Request $request): Response
    {
        return Inertia::render('Sucursal/ProductosCompra/Index', array_merge(
            $this->purchaseProductIndexData($request),
            ['canManage' => true],
        ));
    }

    public function history(PurchaseProduct $producto_compra): JsonResponse
    {
        return $this->purchaseProductHistory($producto_compra);
    }
}
