<?php

namespace App\Http\Controllers\Empresa;

use App\Enums\AiDraftStatus;
use App\Http\Controllers\Controller;
use App\Models\AiCategoryDraft;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\Ai\AiCategoryDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly AiCategoryDraftService $aiDrafts,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
            'includes' => 'nullable|array|max:15',
            'includes.*' => 'nullable|string|max:80',
            'excludes' => 'nullable|array|max:15',
            'excludes.*' => 'nullable|string|max:80',
        ], [
            'name.unique' => 'Ya existe una categoría de gastos con ese nombre.',
        ]);

        ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'aliases' => $this->normalizeStringList($validated['aliases'] ?? null),
            'includes' => $this->normalizeStringList($validated['includes'] ?? null),
            'excludes' => $this->normalizeStringList($validated['excludes'] ?? null),
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'Categoría creada.');
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $tenant = app('tenant');

        if ($category->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->ignore($category->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
            'includes' => 'nullable|array|max:15',
            'includes.*' => 'nullable|string|max:80',
            'excludes' => 'nullable|array|max:15',
            'excludes.*' => 'nullable|string|max:80',
            'status' => 'required|in:active,inactive',
        ], [
            'name.unique' => 'Ya existe otra categoría con ese nombre.',
        ]);

        $category->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'aliases' => $this->normalizeStringList($validated['aliases'] ?? null),
            'includes' => $this->normalizeStringList($validated['includes'] ?? null),
            'excludes' => $this->normalizeStringList($validated['excludes'] ?? null),
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Categoría actualizada.');
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        $tenant = app('tenant');

        if ($category->tenant_id !== $tenant->id) {
            abort(403);
        }

        $subCount = $category->subcategories()->count();
        if ($subCount > 0) {
            return back()->with('error', "No puedes eliminar esta categoría: tiene {$subCount} subcategoría".($subCount === 1 ? '' : 's').'. Desactívala o elimínalas primero.');
        }

        $category->delete();

        return back()->with('success', 'Categoría eliminada.');
    }

    /**
     * POST /{tenant}/empresa/gastos/categorias/ia/aplicar
     *
     * Consume un borrador de IA para crear una nueva categoría con sus
     * subcategorías propuestas (mode=create_new) o extender una existente
     * con mejoras y subcategorías adicionales (mode=use_existing).
     *
     * Devuelve JSON con la categoría resultante para que el frontend
     * actualice el listado sin recargar página completa.
     */
    public function storeFromAiDraft(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $userId = Auth::id();

        $rules = [
            'ai_draft_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['required', 'in:create_new,use_existing'],
            'subcategories' => 'nullable|array|max:8',
            'subcategories.*.name' => 'required|string|max:120',
            'subcategories.*.description' => 'nullable|string|max:500',
            'subcategories.*.aliases' => 'nullable|array|max:10',
            'subcategories.*.aliases.*' => 'nullable|string|max:60',
            'subcategories.*.includes' => 'nullable|array|max:15',
            'subcategories.*.includes.*' => 'nullable|string|max:80',
            'subcategories.*.excludes' => 'nullable|array|max:15',
            'subcategories.*.excludes.*' => 'nullable|string|max:80',
        ];

        $mode = $request->input('mode');

        if ($mode === 'create_new') {
            $rules['category.name'] = [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ];
            $rules['category.description'] = 'nullable|string|max:500';
            $rules['category.aliases'] = 'nullable|array|max:10';
            $rules['category.aliases.*'] = 'nullable|string|max:60';
            $rules['category.includes'] = 'nullable|array|max:15';
            $rules['category.includes.*'] = 'nullable|string|max:80';
            $rules['category.excludes'] = 'nullable|array|max:15';
            $rules['category.excludes.*'] = 'nullable|string|max:80';
        } else {
            $rules['existing_category_id'] = [
                'required',
                Rule::exists('expense_categories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ];
            $rules['category_updates.description'] = 'nullable|string|max:500';
            $rules['category_updates.aliases_to_add'] = 'nullable|array|max:10';
            $rules['category_updates.aliases_to_add.*'] = 'nullable|string|max:60';
            $rules['category_updates.includes_to_add'] = 'nullable|array|max:15';
            $rules['category_updates.includes_to_add.*'] = 'nullable|string|max:80';
            $rules['category_updates.excludes_to_add'] = 'nullable|array|max:15';
            $rules['category_updates.excludes_to_add.*'] = 'nullable|string|max:80';
        }

        $validated = $request->validate($rules, [
            'category.name.unique' => 'Ya existe una categoría con ese nombre.',
            'existing_category_id.exists' => 'La categoría a extender no existe.',
        ]);

        $subcategories = $validated['subcategories'] ?? [];

        // Subcategorías duplicadas EN EL MISMO PAYLOAD (case-insensitive).
        if ($this->hasDuplicateSubcategoryNames($subcategories)) {
            return response()->json([
                'message' => 'Hay subcategorías repetidas en la propuesta. Corrige los nombres antes de guardar.',
            ], 422);
        }

        $draft = $this->resolveAiDraft($validated['ai_draft_id'] ?? null, $tenant->id);

        $category = DB::transaction(function () use ($tenant, $userId, $validated, $subcategories, $draft, $mode) {
            if ($mode === 'create_new') {
                $category = ExpenseCategory::create([
                    'tenant_id' => $tenant->id,
                    'name' => $validated['category']['name'],
                    'description' => $validated['category']['description'] ?? null,
                    'aliases' => $this->normalizeStringList($validated['category']['aliases'] ?? null),
                    'includes' => $this->normalizeStringList($validated['category']['includes'] ?? null),
                    'excludes' => $this->normalizeStringList($validated['category']['excludes'] ?? null),
                    'status' => 'active',
                    'created_by' => $userId,
                ]);
            } else {
                /** @var ExpenseCategory $category */
                $category = ExpenseCategory::where('tenant_id', $tenant->id)
                    ->where('id', $validated['existing_category_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $updates = $validated['category_updates'] ?? [];
                $payload = [];

                if (! empty($updates['description'])) {
                    $payload['description'] = $updates['description'];
                }
                $payload['aliases'] = $this->mergeStringList(
                    $category->aliases ?? [],
                    $updates['aliases_to_add'] ?? [],
                );
                $payload['includes'] = $this->mergeStringList(
                    $category->includes ?? [],
                    $updates['includes_to_add'] ?? [],
                );
                $payload['excludes'] = $this->mergeStringList(
                    $category->excludes ?? [],
                    $updates['excludes_to_add'] ?? [],
                );

                $category->update($payload);
            }

            // Crear subcategorías nuevas (válido para ambos modos).
            // Validamos duplicados contra las subcategorías ya existentes
            // de la categoría destino (incluyendo soft-deleted? no — solo activas
            // visibles; expense_subcategories no usa SoftDeletes).
            $existingSubNames = $category->subcategories()
                ->pluck('name')
                ->map(fn ($n) => mb_strtolower($n))
                ->all();

            $collisions = [];
            foreach ($subcategories as $idx => $sub) {
                if (in_array(mb_strtolower($sub['name']), $existingSubNames, true)) {
                    $collisions[] = "{$sub['name']} (posición ".($idx + 1).')';
                }
            }
            if ($collisions !== []) {
                throw new ValidationException(
                    validator: validator([], []),
                    response: response()->json([
                        'message' => 'Estas subcategorías ya existen en la categoría: '.implode(', ', $collisions).'.',
                    ], 422),
                );
            }

            foreach ($subcategories as $sub) {
                ExpenseSubcategory::create([
                    'tenant_id' => $tenant->id,
                    'expense_category_id' => $category->id,
                    'name' => $sub['name'],
                    'description' => $sub['description'] ?? null,
                    'aliases' => $this->normalizeStringList($sub['aliases'] ?? null),
                    'includes' => $this->normalizeStringList($sub['includes'] ?? null),
                    'excludes' => $this->normalizeStringList($sub['excludes'] ?? null),
                    'status' => 'active',
                    'created_by' => $userId,
                ]);
            }

            if ($draft) {
                $draft->update([
                    'status' => AiDraftStatus::Consumed->value,
                    'expense_category_id' => $category->id,
                    'consumed_at' => now(),
                ]);
                $this->aiDrafts->deleteDraftFiles($draft);
            }

            return $category;
        });

        return response()->json([
            'message' => $mode === 'create_new' ? 'Categoría creada.' : 'Categoría actualizada.',
            'category' => $category->fresh(['subcategories']),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $subcategories
     */
    private function hasDuplicateSubcategoryNames(array $subcategories): bool
    {
        $names = array_map(fn ($s) => mb_strtolower(trim((string) ($s['name'] ?? ''))), $subcategories);
        $names = array_filter($names, fn ($n) => $n !== '');

        return count($names) !== count(array_unique($names));
    }

    private function resolveAiDraft(?int $draftId, int $tenantId): ?AiCategoryDraft
    {
        if (! $draftId) {
            return null;
        }

        return AiCategoryDraft::where('id', $draftId)
            ->where('tenant_id', $tenantId)
            ->where('status', AiDraftStatus::Ready->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Trim + dedupe case-insensitive + drop empties. Devuelve null si queda vacía.
     *
     * @param  array<int, string>|null  $list
     * @return array<int, string>|null
     */
    private function normalizeStringList(?array $list): ?array
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

    /**
     * Merge dos listas de strings con dedupe case-insensitive (preserva la
     * forma original del primer elemento que aparezca).
     *
     * @param  array<int, string>  $current
     * @param  array<int, string>  $additions
     * @return array<int, string>|null
     */
    private function mergeStringList(array $current, array $additions): ?array
    {
        $merged = collect($current)
            ->merge($additions)
            ->map(fn ($a) => trim((string) $a))
            ->filter()
            ->unique(fn ($a) => mb_strtolower($a))
            ->values()
            ->all();

        return $merged === [] ? null : $merged;
    }
}
