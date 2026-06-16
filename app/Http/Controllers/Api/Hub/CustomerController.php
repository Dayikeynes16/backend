<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Clientes activos de la sucursal del usuario, con búsqueda por nombre o
     * teléfono. Apoyo de consulta para la caja.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100']);

        $user = $request->user();
        $search = trim((string) $request->input('search', ''));

        $customers = Customer::withoutGlobalScopes()
            ->where('branch_id', $user->branch_id)
            ->where('status', 'active')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->paginate(30);

        return response()->json([
            'data' => $customers->getCollection()->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
            ])->values(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }
}
