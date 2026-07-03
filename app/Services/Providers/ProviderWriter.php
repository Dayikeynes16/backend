<?php

namespace App\Services\Providers;

use App\Models\Branch;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;

/**
 * Punto único de creación de proveedores (catálogo tenant-wide). Compartido por
 * el trait HandlesProviderWrites (captura manual) y el confirmador del asistente,
 * para no duplicar la lógica ni las reglas de acceso.
 */
final class ProviderWriter
{
    /**
     * Determina si el usuario puede gestionar el catálogo de proveedores.
     * admin-empresa/superadmin siempre; admin-sucursal sólo si su sucursal tiene
     * habilitado el toggle `branch_admin_providers_enabled` (misma regla que el
     * middleware `branch.feature`).
     */
    public static function canManage(User $user): bool
    {
        if ($user->hasRole('admin-empresa') || $user->hasRole('superadmin')) {
            return true;
        }

        if ($user->hasRole('admin-sucursal') && $user->branch_id) {
            return (bool) Branch::find($user->branch_id)?->branch_admin_providers_enabled;
        }

        return false;
    }

    /**
     * @param  array{
     *     name: string,
     *     type: string,
     *     phone?: string|null,
     *     email?: string|null,
     *     rfc?: string|null,
     *     address?: string|null,
     *     notes?: string|null,
     * }  $data  ya validado
     */
    public function create(Tenant $tenant, User $user, array $data): Provider
    {
        return Provider::create(array_merge($data, [
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'created_by' => $user->id,
        ]));
    }
}
