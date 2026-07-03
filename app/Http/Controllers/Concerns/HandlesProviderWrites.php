<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ProviderType;
use App\Models\Provider;
use App\Services\Providers\ProviderWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Escritura del catálogo de proveedores (tenant-wide). Compartido por el
 * controlador de empresa y el de sucursal — el catálogo es el mismo; el acceso
 * del admin-sucursal se gatea por el toggle `branch_admin_providers_enabled`.
 * El borrado (destroy) NO vive aquí: queda reservado a empresa/superadmin.
 */
trait HandlesProviderWrites
{
    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $this->validatedProviderRequest($request, $tenant->id);

        app(ProviderWriter::class)->create($tenant, Auth::user(), $validated);

        return back()->with('success', 'Proveedor creado.');
    }

    public function update(Request $request, Provider $provider): RedirectResponse
    {
        $tenant = app('tenant');
        if ($provider->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $this->validatedProviderRequest($request, $tenant->id, $provider->id, withStatus: true);

        $provider->update($validated);

        return back()->with('success', 'Proveedor actualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedProviderRequest(Request $request, int $tenantId, ?int $ignoreId = null, bool $withStatus = false): array
    {
        $nameRule = Rule::unique('providers', 'name')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'));
        if ($ignoreId) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $rules = [
            'name' => ['required', 'string', 'max:160', $nameRule],
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            'rfc' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'type' => ['required', Rule::enum(ProviderType::class)],
            'notes' => 'nullable|string|max:1000',
        ];
        if ($withStatus) {
            $rules['status'] = 'required|in:active,inactive';
        }

        return $request->validate($rules, [
            'name.unique' => 'Ya existe un proveedor con ese nombre.',
        ]);
    }
}
