<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Solo lectura del catálogo de proveedores para admin-sucursal. El CRUD
 * vive en Empresa (los proveedores son tenant-wide). admin-sucursal sólo
 * los consulta para asignarlos a sus compras.
 */
class ProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('q', ''));
        $typeFilter = $request->input('type');

        $providers = Provider::query()
            ->where('status', 'active')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                        ->orWhereRaw('LOWER(contact_name) LIKE ?', ['%'.mb_strtolower($search).'%']);
                });
            })
            ->when($typeFilter, fn ($q) => $q->where('type', $typeFilter))
            ->orderBy('name')
            ->get()
            ->map(fn (Provider $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'contact_name' => $p->contact_name,
                'phone' => $p->phone,
                'type' => $p->type instanceof ProviderType ? $p->type->value : $p->type,
                'type_label' => $p->type instanceof ProviderType ? $this->typeLabel($p->type) : (string) $p->type,
            ]);

        return Inertia::render('Sucursal/Proveedores/Index', [
            'providers' => $providers,
            'filters' => ['q' => $search, 'type' => $typeFilter],
            'types' => array_map(fn (ProviderType $t) => [
                'value' => $t->value,
                'label' => $this->typeLabel($t),
            ], ProviderType::cases()),
        ]);
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
