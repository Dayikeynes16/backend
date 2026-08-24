<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SaleMovementsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Movimientos sobre ventas ya cobradas, limitados a la sucursal del usuario.
 *
 * La ruta va detrás de `branch.feature:branch_admin_movements_enabled`: el flag
 * nace apagado porque esta pantalla vigila a quien opera la caja, y la cuenta de
 * admin-sucursal es una de las que puede quedar bajo sospecha. La enciende el
 * admin-empresa, que siempre ve todo desde su propia pantalla.
 */
class MovimientosController extends Controller
{
    public function index(Request $request, SaleMovementsQuery $query): Response
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'event' => 'nullable|string|in:'.implode(',', AuditEvent::saleMovements()),
            'user_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $filters = $request->only(['from', 'to', 'event', 'user_id']);

        // La sucursal se fuerza desde el usuario, no desde la query: mandar otro
        // branch_id en la URL no debe ampliar el alcance.
        ['sales' => $sales, 'summary' => $summary] = $query->run($filters, $user->branch_id);

        return Inertia::render('Sucursal/Movimientos/Index', [
            'sales' => $sales,
            'summary' => $summary,
            'branches' => [],
            'users' => User::where('branch_id', $user->branch_id)->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'eventLabels' => $this->eventLabels(),
            'tenant' => app('tenant'),
            'scope' => 'sucursal',
        ]);
    }

    /** @return array<string, string> */
    private function eventLabels(): array
    {
        $labels = [];
        foreach (AuditEvent::saleMovements() as $value) {
            $labels[$value] = AuditEvent::from($value)->label();
        }

        return $labels;
    }
}
