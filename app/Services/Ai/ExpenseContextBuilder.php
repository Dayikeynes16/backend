<?php

namespace App\Services\Ai;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Construye el contexto que se envía al modelo de IA para clasificar gastos.
 *
 * Reglas:
 * - Solo categorías y subcategorías ACTIVAS del tenant actual.
 * - Sucursales filtradas por permisos del usuario (admin-sucursal sólo ve la suya).
 * - Nada de datos sensibles del tenant (RFC, teléfono, etc).
 * - Catálogo aplanado: cada subcategoría incluye su categoría padre para evitar
 *   ambigüedad cuando el modelo elige un id directamente.
 */
class ExpenseContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Tenant $tenant, User $user): array
    {
        $categories = ExpenseCategory::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with(['subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'aliases']);

        $branches = $this->visibleBranches($tenant, $user);

        return [
            'tenant' => ['slug' => $tenant->slug, 'name' => $tenant->name],
            'usuario' => [
                'rol' => $user->getRoleNames()->first(),
                'sucursal_id' => $user->branch_id,
            ],
            'fecha_actual' => now()->format('Y-m-d'),
            'sucursales' => $branches->map(fn (Branch $b) => [
                'id' => $b->id,
                'nombre' => $b->name,
            ])->values()->all(),
            'metodos_pago' => collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                ->all(),
            'categorias' => $categories->map(fn (ExpenseCategory $c) => [
                'id' => $c->id,
                'nombre' => $c->name,
                'descripcion' => $c->description,
                'aliases' => $c->aliases ?? [],
                'subcategorias' => $c->subcategories->map(fn ($s) => [
                    'id' => $s->id,
                    'nombre' => $s->name,
                    'descripcion' => $s->description,
                    'aliases' => $s->aliases ?? [],
                ])->values()->all(),
            ])->values()->all(),
            'reglas' => [
                'Usa SIEMPRE un expense_subcategory_id que exista en el catálogo cuando haya match razonable.',
                'Si nada aplica claramente, deja expense_subcategory_id en null y propón nueva en sugerencia_nueva_categoria.',
                'Nunca inventes ids; sólo usa los que aparecen en el catálogo.',
                'Si dudas entre dos opciones, elige la de mayor especificidad (subcategoría más concreta).',
                'Considera los aliases como sinónimos válidos del nombre principal — eso evita duplicados.',
                'Para admin-sucursal, ignora branch_id (lo decide el sistema). Para admin-empresa, intenta inferirlo de la mención explícita de una sucursal en el ticket o el texto.',
                'metodo_pago debe ser uno de los slugs en metodos_pago (cash/card/transfer) o null si no es claro.',
                'Confianza "alta" requiere monto, concepto y subcategoría claros. Si el monto no se lee bien, baja a "media" o "baja".',
                'Fechas relativas ("hoy", "ayer") resuélvelas con fecha_actual y zona horaria America/Mexico_City.',
                'Si falta información clave (monto, concepto), agrégala a campos_faltantes.',
            ],
        ];
    }

    /**
     * Devuelve sólo las sucursales que el usuario puede registrar gastos en ellas.
     */
    private function visibleBranches(Tenant $tenant, User $user): Collection
    {
        $query = Branch::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('name');

        if ($user->hasRole('admin-sucursal') && ! $user->hasRole('superadmin')) {
            $query->where('id', $user->branch_id);
        }

        return $query->get(['id', 'name']);
    }
}
