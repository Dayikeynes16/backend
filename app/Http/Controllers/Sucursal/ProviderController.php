<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\ProviderType;
use App\Http\Controllers\Concerns\HandlesProviderDetail;
use App\Http\Controllers\Concerns\HandlesProviderWrites;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Provider;
use App\Models\PurchaseProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de proveedores para admin-sucursal. Los proveedores son tenant-wide;
 * el admin-sucursal siempre los consulta para asignarlos a sus compras, y puede
 * crearlos/editarlos cuando la empresa habilita el toggle
 * `branch_admin_providers_enabled` de su sucursal (las rutas de escritura se
 * gatean con el middleware `branch.feature`). El borrado queda en empresa.
 */
class ProviderController extends Controller
{
    use HandlesProviderDetail;
    use HandlesProviderWrites;

    public function index(Request $request): Response
    {
        $canManage = $this->canManageProviders();

        $search = trim((string) $request->input('q', ''));
        $typeFilter = $request->input('type');

        $providers = Provider::query()
            // Cuando puede gestionar, mostramos también inactivos para poder
            // reactivarlos/editarlos; de lo contrario solo los activos.
            ->when(! $canManage, fn ($q) => $q->where('status', 'active'))
            ->when($search !== '', function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
            })
            ->when($typeFilter, fn ($q) => $q->where('type', $typeFilter))
            ->orderBy('name')
            ->get()
            ->map(fn (Provider $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'phone' => $p->phone,
                'email' => $p->email,
                'rfc' => $p->rfc,
                'address' => $p->address,
                'notes' => $p->notes,
                'status' => $p->status,
                'type' => $p->type instanceof ProviderType ? $p->type->value : $p->type,
                'type_label' => $p->type instanceof ProviderType ? $this->typeLabel($p->type) : (string) $p->type,
            ]);

        return Inertia::render('Sucursal/Proveedores/Index', [
            'providers' => $providers,
            'filters' => ['q' => $search, 'type' => $typeFilter],
            'canManage' => $canManage,
            'types' => array_map(fn (ProviderType $t) => [
                'value' => $t->value,
                'label' => $this->typeLabel($t),
            ], ProviderType::cases()),
        ]);
    }

    /**
     * El admin-sucursal puede gestionar proveedores si la empresa habilitó el
     * toggle en su sucursal. superadmin siempre puede.
     */
    private function canManageProviders(): bool
    {
        $user = Auth::user();

        if ($user->hasRole('superadmin')) {
            return true;
        }

        $branch = $user->branch_id ? Branch::find($user->branch_id) : null;

        return (bool) ($branch?->branch_admin_providers_enabled);
    }

    /**
     * Detalle de un proveedor desde la sucursal (solo consulta). Los datos de
     * compras/pagos/deuda van scopeados a SU sucursal vía branchIdForProviderDetail().
     */
    public function show(Provider $provider): Response
    {
        $this->assertProviderVisible($provider);

        return Inertia::render('Sucursal/Proveedores/Show', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'phone' => $provider->phone,
                'email' => $provider->email,
                'rfc' => $provider->rfc,
                'address' => $provider->address,
                'type' => $provider->type instanceof ProviderType ? $provider->type->value : $provider->type,
                'type_label' => $provider->type instanceof ProviderType ? $this->typeLabel($provider->type) : (string) $provider->type,
                'notes' => $provider->notes,
                'status' => $provider->status,
            ],
            'seed' => $this->providerSeed($provider),
            'purchaseProducts' => PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    protected function branchIdForProviderDetail(): ?int
    {
        return (int) Auth::user()->branch_id;
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
