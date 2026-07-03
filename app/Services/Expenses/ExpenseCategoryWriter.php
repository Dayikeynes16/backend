<?php

namespace App\Services\Expenses;

use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Creación del catálogo de categorías/subcategorías de gasto (tenant-wide).
 * Compartido por HandlesExpenseCategoryWrites (captura manual) y el confirmador
 * del asistente, para no duplicar la creación ni las reglas de acceso.
 *
 * Ámbito del asistente: crear categoría nueva o subcategoría bajo una existente.
 * La extensión/merge de categorías existentes sigue viviendo en el trait.
 */
final class ExpenseCategoryWriter
{
    /**
     * admin-empresa/superadmin siempre; admin-sucursal sólo si su sucursal tiene
     * habilitado el toggle `branch_admin_expense_categories_enabled`.
     */
    public static function canManage(User $user): bool
    {
        if ($user->hasRole('admin-empresa') || $user->hasRole('superadmin')) {
            return true;
        }

        if ($user->hasRole('admin-sucursal') && $user->branch_id) {
            return (bool) Branch::find($user->branch_id)?->branch_admin_expense_categories_enabled;
        }

        return false;
    }

    /**
     * @param  array{name: string, description?: string|null, aliases?: array<int, string>|null, includes?: array<int, string>|null, excludes?: array<int, string>|null}  $data
     */
    public function createCategory(Tenant $tenant, User $user, array $data): ExpenseCategory
    {
        return ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'aliases' => self::normalizeList($data['aliases'] ?? null),
            'includes' => self::normalizeList($data['includes'] ?? null),
            'excludes' => self::normalizeList($data['excludes'] ?? null),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array{name: string, description?: string|null, aliases?: array<int, string>|null, includes?: array<int, string>|null, excludes?: array<int, string>|null}  $data
     *
     * @throws ValidationException si el nombre ya existe en la categoría
     */
    public function createSubcategory(Tenant $tenant, User $user, ExpenseCategory $category, array $data): ExpenseSubcategory
    {
        $name = trim((string) $data['name']);

        $exists = $category->subcategories()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe una subcategoría con ese nombre en esta categoría.',
            ]);
        }

        return ExpenseSubcategory::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'aliases' => self::normalizeList($data['aliases'] ?? null),
            'includes' => self::normalizeList($data['includes'] ?? null),
            'excludes' => self::normalizeList($data['excludes'] ?? null),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    /**
     * Edición parcial de una categoría (sólo name/description/status). Aliases/
     * includes/excludes quedan como estén — el asistente no los toca.
     *
     * @param  array{name?: string, description?: string|null, status?: string}  $data
     */
    public function updateCategory(ExpenseCategory $category, array $data): ExpenseCategory
    {
        $category->update($this->pickWritable($data));

        return $category->fresh();
    }

    /**
     * Edición parcial de una subcategoría. Valida unicidad de nombre dentro de
     * su categoría (ignorándose a sí misma).
     *
     * @param  array{name?: string, description?: string|null, status?: string}  $data
     *
     * @throws ValidationException
     */
    public function updateSubcategory(ExpenseSubcategory $subcategory, array $data): ExpenseSubcategory
    {
        if (isset($data['name'])) {
            $exists = ExpenseSubcategory::query()
                ->where('expense_category_id', $subcategory->expense_category_id)
                ->where('id', '!=', $subcategory->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => 'Ya existe otra subcategoría con ese nombre en la categoría.',
                ]);
            }
        }

        $subcategory->update($this->pickWritable($data));

        return $subcategory->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pickWritable(array $data): array
    {
        return array_intersect_key($data, array_flip(['name', 'description', 'status']));
    }

    /**
     * Trim + dedupe case-insensitive + drop empties. Devuelve null si queda vacía.
     *
     * @param  array<int, string>|null  $list
     * @return array<int, string>|null
     */
    public static function normalizeList(?array $list): ?array
    {
        if (! $list) {
            return null;
        }

        $cleaned = collect($list)
            ->map(fn ($a) => trim((string) $a))
            ->filter()
            ->unique(fn ($a) => mb_strtolower($a))
            ->values()
            ->all();

        return $cleaned === [] ? null : $cleaned;
    }
}
