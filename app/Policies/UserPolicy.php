<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function resetPassword(User $admin, User $target): bool
    {
        // Cannot reset your own password via admin reset
        if ($admin->id === $target->id) {
            return false;
        }

        // Cannot reset another superadmin's password
        if ($target->hasRole('superadmin')) {
            return false;
        }

        // Superadmin can reset anyone (except other superadmins, handled above)
        if ($admin->hasRole('superadmin')) {
            return true;
        }

        // Admin-empresa can reset users within their own tenant only
        if ($admin->hasRole('admin-empresa')) {
            return $target->tenant_id !== null
                && $admin->tenant_id === $target->tenant_id;
        }

        return false;
    }

    public function update(User $admin, User $target): bool
    {
        if ($target->hasRole('superadmin') && ! $admin->hasRole('superadmin')) {
            return false;
        }

        if ($admin->hasRole('superadmin')) {
            return true;
        }

        if ($admin->hasRole('admin-empresa')) {
            return $target->tenant_id !== null
                && $admin->tenant_id === $target->tenant_id;
        }

        if ($admin->hasRole('admin-sucursal')) {
            return $target->branch_id !== null
                && $admin->branch_id === $target->branch_id
                && $target->hasRole('cajero');
        }

        return false;
    }

    public function delete(User $admin, User $target): bool
    {
        return $this->update($admin, $target);
    }
}
