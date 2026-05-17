<?php

namespace App\Services\Ai;

use App\Models\ExpenseCategory;
use App\Models\Tenant;

/**
 * Construye el contexto que se envía a OpenAI cuando un admin-empresa quiere
 * crear una categoría con IA.
 *
 * El objetivo principal del contexto es EVITAR DUPLICADOS: la IA recibe el
 * catálogo completo del tenant para que pueda detectar solapamientos y
 * preferir reutilizar/extender una categoría existente antes que crear una
 * nueva similar.
 */
class CategoryContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Tenant $tenant): array
    {
        $categories = ExpenseCategory::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with(['subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'aliases', 'includes', 'excludes']);

        return [
            'tenant' => ['slug' => $tenant->slug, 'name' => $tenant->name],
            'fecha_actual' => now()->format('Y-m-d'),
            'categorias_existentes' => $categories->map(fn (ExpenseCategory $c) => [
                'id' => $c->id,
                'nombre' => $c->name,
                'descripcion' => $c->description,
                'aliases' => $c->aliases ?? [],
                'incluye' => $c->includes ?? [],
                'no_incluye' => $c->excludes ?? [],
                'subcategorias' => $c->subcategories->map(fn ($s) => [
                    'nombre' => $s->name,
                    'descripcion' => $s->description,
                    'aliases' => $s->aliases ?? [],
                ])->values()->all(),
            ])->values()->all(),
            'reglas' => [
                'Si una categoría existente cubre razonablemente la intención del usuario, responde accion_sugerida="usar_existente" con su id, en lugar de proponer una categoría nueva similar.',
                'Solo propón categorías nuevas si NO hay solapamiento claro con ninguna existente.',
                'Para evitar duplicados, considera aliases, descripciones e includes de las categorías existentes — no solo el nombre.',
                'Si la intención del usuario es ambigua o falta información clave, responde accion_sugerida="necesita_aclaracion" con preguntas_faltantes específicas.',
                'Nombre de categoría: 120 chars máx. Descripción: 500 máx. Máx 10 aliases, 15 includes, 15 excludes, 8 subcategorías propuestas.',
                'Cada subcategoría incluye nombre + descripción + aliases + includes + excludes. No inventes ids — sólo nombres.',
                'Si propones reutilizar (usar_existente), también puedes proponer mejoras: nuevos aliases/includes/excludes a agregar y subcategorías a crear dentro de la existente. No dupliques subcategorías ya existentes.',
                'No incluyas comentarios en ningún campo. Solo texto descriptivo limpio.',
            ],
        ];
    }
}
