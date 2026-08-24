<?php

namespace App\Http\Controllers\Empresa;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\SaleMovementsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Movimientos sobre ventas ya cobradas, todas las sucursales de la empresa.
 *
 * El admin-empresa entra siempre. Su par de sucursal
 * (`Sucursal\MovimientosController`) ve solo la suya y depende del flag
 * `branch_admin_movements_enabled`.
 */
class MovimientosController extends Controller
{
    public function index(Request $request, SaleMovementsQuery $query): Response
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
            'event' => 'nullable|string|in:'.implode(',', AuditEvent::saleMovements()),
            'user_id' => 'nullable|integer',
        ]);

        $filters = $request->only(['from', 'to', 'branch_id', 'event', 'user_id']);

        // La sucursal filtrada debe ser de esta empresa: sin esto, un branch_id
        // de otro tenant en la URL colaría movimientos ajenos.
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        if (! empty($filters['branch_id']) && ! $branches->contains('id', (int) $filters['branch_id'])) {
            $filters['branch_id'] = null;
        }

        ['sales' => $sales, 'summary' => $summary] = $query->run($filters);

        return Inertia::render('Empresa/Movimientos/Index', [
            'sales' => $sales,
            'summary' => $summary,
            'branches' => $branches,
            'users' => User::whereNotNull('branch_id')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'eventLabels' => $this->eventLabels(),
            'tenant' => app('tenant'),
            'scope' => 'empresa',
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
