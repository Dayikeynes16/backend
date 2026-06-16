<?php

namespace App\Http\Controllers\Empresa;

use App\Enums\ProviderType;
use App\Http\Controllers\Concerns\HandlesProviderDetail;
use App\Http\Controllers\Concerns\HandlesProviderWrites;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\PurchaseProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de proveedores para admin-empresa. Cada proveedor pertenece al tenant
 * y aparece en TODAS las sucursales del tenant cuando se registran compras.
 * No hay scope por sucursal — el catálogo es tenant-wide (igual que el de
 * categorías de gasto).
 */
class ProviderController extends Controller
{
    use HandlesProviderDetail;
    use HandlesProviderWrites;

    public function index(Request $request): Response
    {
        $tenant = app('tenant');

        $search = trim((string) $request->input('q', ''));
        $typeFilter = $request->input('type');
        $statusFilter = $request->input('status', 'active');

        $providers = Provider::query()
            ->withSum([
                'purchases as pending_total' => function ($q) {
                    $q->whereNull('cancelled_at')->where('amount_pending', '>', 0);
                },
            ], 'amount_pending')
            ->withCount('purchases')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                        ->orWhere('rfc', 'ilike', '%'.$search.'%');
                });
            })
            ->when($typeFilter, fn ($q) => $q->where('type', $typeFilter))
            ->when($statusFilter !== 'all', fn ($q) => $q->where('status', $statusFilter))
            ->orderBy('name')
            ->get()
            ->map(fn (Provider $p) => $this->serializeProvider($p));

        return Inertia::render('Empresa/Proveedores/Index', [
            'providers' => $providers,
            'filters' => [
                'q' => $search,
                'type' => $typeFilter,
                'status' => $statusFilter,
            ],
            'types' => array_map(fn (ProviderType $t) => [
                'value' => $t->value,
                'label' => $this->typeLabel($t),
            ], ProviderType::cases()),
            'kpis' => [
                'total_active' => Provider::where('status', 'active')->count(),
                'total_inactive' => Provider::where('status', 'inactive')->count(),
                'with_pending_debt' => Provider::query()
                    ->whereHas('purchases', fn ($q) => $q->where('amount_pending', '>', 0)->whereNull('cancelled_at'))
                    ->count(),
            ],
        ]);
    }

    public function show(Provider $provider): Response
    {
        $tenant = app('tenant');
        if ($provider->tenant_id !== $tenant->id) {
            abort(404);
        }

        return Inertia::render('Empresa/Proveedores/Show', [
            'provider' => $this->serializeProvider($provider->loadCount('purchases')),
            'seed' => $this->providerSeed($provider),
            'purchaseProducts' => PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    public function destroy(Provider $provider): RedirectResponse
    {
        $tenant = app('tenant');
        if ($provider->tenant_id !== $tenant->id) {
            abort(403);
        }

        // No permitimos borrar si tiene compras vivas (no canceladas).
        $vivePurchases = DB::table('purchases')
            ->where('provider_id', $provider->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($vivePurchases) {
            return back()->withErrors([
                'provider' => 'No puedes eliminar un proveedor con compras vivas. Marca como inactivo en su lugar.',
            ]);
        }

        $provider->delete();

        return back()->with('success', 'Proveedor eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProvider(Provider $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'phone' => $p->phone,
            'email' => $p->email,
            'rfc' => $p->rfc,
            'address' => $p->address,
            'type' => $p->type instanceof ProviderType ? $p->type->value : $p->type,
            'type_label' => $p->type instanceof ProviderType ? $this->typeLabel($p->type) : (string) $p->type,
            'notes' => $p->notes,
            'status' => $p->status,
            'purchases_count' => (int) ($p->purchases_count ?? 0),
            'pending_total' => (float) ($p->pending_total ?? 0),
        ];
    }

    private function typeLabel(ProviderType $type): string
    {
        return match ($type) {
            ProviderType::Ganadero => 'Ganadero',
            ProviderType::MayoristaCarne => 'Mayorista de carne',
            ProviderType::Insumos => 'Insumos',
            ProviderType::Servicios => 'Servicios',
            ProviderType::Otro => 'Otro',
        };
    }
}
