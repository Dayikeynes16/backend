<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Concerns\HandlesExpenseCategoryWrites;
use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\AiCategoryDraftService;
use App\Services\Expenses\ExpenseCategoryWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Gestión del catálogo de categorías/subcategorías de gasto desde el hub.
 * Paridad con la pestaña "Categorías" web del admin-sucursal: mismas reglas,
 * mensajes y alcance que HandlesExpenseCategoryWrites /
 * HandlesExpenseSubcategoryWrites (sin borrado — reservado a empresa), gateado
 * por ExpenseCategoryWriter::canManage (toggle
 * `branch_admin_expense_categories_enabled`, como el middleware branch.feature
 * de las rutas web).
 */
class ExpenseCategoryController extends Controller
{
    // storeFromAiDraft (aplicar propuesta IA) viene del trait web — reuso
    // literal; sus métodos store/update (Inertia) no se rutean aquí.
    use HandlesExpenseCategoryWrites;

    public function __construct(private readonly AiCategoryDraftService $aiDrafts) {}

    /**
     * Borrador de categoría por IA (texto y/o nota de voz → GPT-4o/Whisper).
     * Espeja Ai\CategoryDraftController@store; el hub lo gatea igual que la
     * ruta web de sucursal (rol + toggle). No acepta imágenes (como la web).
     */
    public function aiDraft(Request $request): JsonResponse
    {
        $user = $this->ensureCanManage($request);

        $maxAudioKb = (int) (config('ai.expenses.max_audio_bytes', 10 * 1024 * 1024) / 1024);

        $validated = $request->validate([
            'input_text' => ['nullable', 'string', 'max:'.config('ai.expenses.max_input_text_length', 2000)],
            'audio' => ['nullable', 'file', 'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac', 'max:'.$maxAudioKb],
        ], [
            'audio.mimes' => 'Formato de audio no permitido.',
            'audio.max' => 'El audio no puede superar '.round($maxAudioKb / 1024).' MB.',
        ]);

        $text = $validated['input_text'] ?? null;
        $audio = $request->file('audio');

        if (trim((string) $text) === '' && $audio === null) {
            return response()->json(['message' => 'Aporta un texto o una nota de voz describiendo la categoría.'], 422);
        }

        try {
            $draft = $this->aiDrafts->createDraft($user->tenant, $user, $text, $audio);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No pude analizar tu solicitud. Intenta de nuevo o crea la categoría manualmente.'], 502);
        }

        return response()->json([
            'draft_id' => $draft->id,
            'status' => $draft->status->value,
            'proposal' => $draft->parsed_proposal,
            'audio_transcription' => $draft->audio_transcription,
        ]);
    }

    /**
     * Aplica la propuesta revisada (create_new / use_existing). Delega en el
     * storeFromAiDraft del trait web tras fijar el usuario del guard (el trait
     * usa Auth::id() en contexto web; con Sanctum hay que fijarlo explícito,
     * mismo patrón que HandlesPurchases en el hub).
     */
    public function aiApply(Request $request): JsonResponse
    {
        $user = $this->ensureCanManage($request);
        Auth::setUser($user);

        return $this->storeFromAiDraft($request);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanManage($request);

        // Como la web cuando canManage: TODO el catálogo tenant-wide, incluidas
        // inactivas, ordenado por nombre.
        $categories = ExpenseCategory::with([
            'subcategories' => fn ($q) => $q->orderBy('name'),
        ])->orderBy('name')->get();

        return response()->json([
            'data' => $categories->map(fn (ExpenseCategory $c) => $this->categoryPayload($c))->values(),
        ]);
    }

    public function storeCategory(Request $request, ExpenseCategoryWriter $writer): JsonResponse
    {
        $user = $this->ensureCanManage($request);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $user->tenant_id)),
            ],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
        ], [
            'name.unique' => 'Ya existe una categoría de gastos con ese nombre.',
        ]);

        $category = $writer->createCategory($user->tenant, $user, $validated);

        return response()->json([
            'message' => 'Categoría creada.',
            'data' => $this->categoryPayload($category->load('subcategories')),
        ], 201);
    }

    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $user = $this->ensureCanManage($request);
        $found = ExpenseCategory::findOrFail($category);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->ignore($found->id)
                    ->where(fn ($q) => $q->where('tenant_id', $user->tenant_id)),
            ],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
            'status' => 'required|in:active,inactive',
        ], [
            'name.unique' => 'Ya existe otra categoría con ese nombre.',
        ]);

        $found->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'aliases' => ExpenseCategoryWriter::normalizeList($validated['aliases'] ?? null),
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Categoría actualizada.',
            'data' => $this->categoryPayload($found->fresh()->load('subcategories')),
        ]);
    }

    public function storeSubcategory(Request $request, ExpenseCategoryWriter $writer): JsonResponse
    {
        $user = $this->ensureCanManage($request);

        $validated = $request->validate([
            'expense_category_id' => [
                'required',
                Rule::exists('expense_categories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $user->tenant_id)),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
        ], [
            'expense_category_id.exists' => 'Categoría inválida.',
        ]);

        // Pre-chequeo con el mensaje exacto de la web; el Writer valida de
        // nuevo (case-insensitive) dentro de la creación.
        $duplicate = ExpenseSubcategory::where('expense_category_id', $validated['expense_category_id'])
            ->where('name', $validated['name'])
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe esa subcategoría dentro de la categoría.',
            ]);
        }

        $categoryModel = ExpenseCategory::findOrFail($validated['expense_category_id']);
        $subcategory = $writer->createSubcategory($user->tenant, $user, $categoryModel, $validated);

        return response()->json([
            'message' => 'Subcategoría creada.',
            'data' => $this->subcategoryPayload($subcategory),
        ], 201);
    }

    public function updateSubcategory(Request $request, int $subcategory): JsonResponse
    {
        $this->ensureCanManage($request);
        $found = ExpenseSubcategory::findOrFail($subcategory);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => 'nullable|string|max:500',
            'aliases' => 'nullable|array|max:10',
            'aliases.*' => 'nullable|string|max:60',
            'status' => 'required|in:active,inactive',
        ]);

        $duplicate = ExpenseSubcategory::where('expense_category_id', $found->expense_category_id)
            ->where('name', $validated['name'])
            ->where('id', '!=', $found->id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe otra subcategoría con ese nombre en la categoría.',
            ]);
        }

        $found->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'aliases' => ExpenseCategoryWriter::normalizeList($validated['aliases'] ?? null),
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Subcategoría actualizada.',
            'data' => $this->subcategoryPayload($found->fresh()),
        ]);
    }

    /**
     * Solo admin-sucursal con el toggle habilitado (misma regla que el
     * middleware web branch.feature:branch_admin_expense_categories_enabled).
     */
    private function ensureCanManage(Request $request): User
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        abort_unless($user->hasRole('admin-sucursal'), 403, 'Solo el administrador de sucursal puede gestionar el catálogo.');
        abort_unless(
            ExpenseCategoryWriter::canManage($user),
            403,
            'Tu empresa no ha habilitado esta función para tu sucursal.'
        );

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryPayload(ExpenseCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'aliases' => $category->aliases ?? [],
            'status' => $category->status,
            'subcategories' => $category->subcategories
                ->map(fn (ExpenseSubcategory $s) => $this->subcategoryPayload($s))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subcategoryPayload(ExpenseSubcategory $subcategory): array
    {
        return [
            'id' => $subcategory->id,
            'expense_category_id' => $subcategory->expense_category_id,
            'name' => $subcategory->name,
            'description' => $subcategory->description,
            'aliases' => $subcategory->aliases ?? [],
            'status' => $subcategory->status,
        ];
    }
}
