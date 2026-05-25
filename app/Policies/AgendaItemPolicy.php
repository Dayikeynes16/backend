<?php

namespace App\Policies;

use App\Enums\AgendaScope;
use App\Models\AgendaItem;
use App\Models\User;

class AgendaItemPolicy
{
    private function isCompanyAdmin(User $user): bool
    {
        return $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
    }

    public function view(User $user, AgendaItem $item): bool
    {
        if ($item->tenant_id !== $user->tenant_id) {
            return false;
        }
        if ($item->scope === AgendaScope::Company) {
            return true;
        }
        if ($item->assigned_to_user_id === $user->id) {
            return true;
        }
        if ($item->scope === AgendaScope::Personal) {
            return $item->user_id === $user->id;
        }

        // branch
        return $this->isCompanyAdmin($user) || $item->branch_id === $user->branch_id;
    }

    public function update(User $user, AgendaItem $item): bool
    {
        if ($item->tenant_id !== $user->tenant_id) {
            return false;
        }
        if ($item->user_id === $user->id) {
            return true;
        }
        if ($this->isCompanyAdmin($user)) {
            return true;
        }
        if ($item->scope === AgendaScope::Branch
            && $user->hasRole('admin-sucursal')
            && $item->branch_id === $user->branch_id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, AgendaItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function cancel(User $user, AgendaItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function complete(User $user, AgendaItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id
            && ($item->user_id === $user->id || $item->assigned_to_user_id === $user->id);
    }

    /** Ability sin modelo: ¿puede crear un ítem con este scope? */
    public function createScope(User $user, string $scope): bool
    {
        return match ($scope) {
            AgendaScope::Company->value => $this->isCompanyAdmin($user),
            AgendaScope::Branch->value => true, // controlador valida que sea su sucursal
            default => true, // personal
        };
    }
}
