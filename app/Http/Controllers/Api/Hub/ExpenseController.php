<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\AiDraftStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubExpenseResource;
use App\Models\AiExpenseDraft;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Ai\AiExpenseDraftService;
use App\Services\AuditLogger;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ExpenseController extends Controller
{
    /**
     * Gastos en efectivo del cajero (su turno) + árbol de categorías para el
     * formulario. Misma semántica que Caja\GastoController.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'expense_category_id' => 'nullable|integer',
            'expense_subcategory_id' => 'nullable|integer',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $search = trim((string) $request->input('search', ''));

        // Espeja la web: el admin-sucursal ve TODOS los gastos de su sucursal
        // (Sucursal\GastoController scopea solo por branch_id); el cajero solo
        // los suyos (Caja\GastoController añade user_id).
        $isAdmin = $user->hasRole('admin-sucursal');

        $baseQuery = Expense::where('branch_id', $user->branch_id)
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->whereNull('cancelled_by')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_at', '<=', $request->date('to')))
            // Filtros por categoría/subcategoría/método (paridad Sucursal\GastoController).
            ->when($request->expense_category_id, fn ($q, $cat) => $q->whereHas(
                'subcategory', fn ($sq) => $sq->where('expense_category_id', $cat)
            ))
            ->when($request->expense_subcategory_id, fn ($q, $sub) => $q->where('expense_subcategory_id', $sub))
            ->when($request->payment_method, fn ($q, $pm) => $q->where('payment_method', $pm))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('concept', 'ilike', "%{$search}%")
                ->orWhere('description', 'ilike', "%{$search}%")));

        // Total exacto sobre TODO el conjunto filtrado, no solo las 50 filas.
        $total = (float) (clone $baseQuery)->sum('amount');

        $expenses = (clone $baseQuery)
            ->with(['subcategory.category', 'attachments', 'user:id,name'])
            ->orderByDesc('expense_at')
            ->paginate(20);

        $categories = ExpenseCategory::with([
            'subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('name'),
        ])->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        // Contexto del turno: total que sale del cajón afecta el corte.
        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();

        // Paridad con la web: el cajero solo puede corregir gastos de su turno
        // abierto (Caja\GastoController::can_manage); el admin, cualquiera.
        $items = collect($expenses->items())->each(function (Expense $e) use ($isAdmin, $shift) {
            $e->setAttribute('can_manage', $isAdmin || ($shift && $e->cash_register_shift_id === $shift->id));
        });

        return response()->json([
            'data' => HubExpenseResource::collection($items),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'total_count' => $expenses->total(),
            ],
            'is_admin' => $isAdmin,
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subcategories' => $c->subcategories->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ])->values(),
            'total' => $total,
            // Métodos de pago para el gasto (solo el admin puede elegir; el
            // cajero queda fijo en efectivo, igual que la web).
            'payment_methods' => collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                ->values(),
            'shift' => $shift ? [
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'opening_amount' => (float) $shift->opening_amount,
            ] : null,
        ]);
    }

    public function show(Request $request, int $expense): JsonResponse
    {
        $found = $this->findOwnExpense($request, $expense);

        return response()->json(['data' => HubExpenseResource::make($found->load(['subcategory.category', 'attachments']))]);
    }

    public function update(Request $request, int $expense): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $found = $this->findOwnExpense($request, $expense);
        $this->assertCanMutate($request, $found);
        if ($found->cancelled_by !== null) {
            return response()->json(['message' => 'No se puede editar un gasto cancelado.'], 422);
        }

        $isAdmin = $user->hasRole('admin-sucursal');

        $validated = $request->validate([
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')->where(
                    fn ($q) => $q->where('tenant_id', $user->tenant_id)->where('status', 'active')
                ),
            ],
            'description' => 'nullable|string|max:1000',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $found->update([
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'description' => $validated['description'] ?? null,
            // Solo el admin puede cambiar el método (paridad Sucursal\GastoController,
            // donde vacío = null); el gasto del cajero queda fijo en efectivo.
            'payment_method' => $isAdmin
                ? ($validated['payment_method'] ?? null)
                : $found->payment_method,
            'updated_by' => $user->id,
        ]);

        return response()->json(['data' => HubExpenseResource::make($found->refresh()->load(['subcategory.category', 'attachments']))]);
    }

    public function destroy(Request $request, int $expense): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $found = $this->findOwnExpense($request, $expense);
        $this->assertCanMutate($request, $found);
        if ($found->cancelled_by !== null) {
            return response()->json(['message' => 'Este gasto ya fue cancelado.'], 422);
        }

        $validated = $request->validate(['cancellation_reason' => 'nullable|string|max:255']);

        $found->update([
            'cancelled_by' => $user->id,
            'cancellation_reason' => $validated['cancellation_reason'] ?? null,
        ]);

        return response()->json(['action' => 'cancelled']);
    }

    public function storeAttachment(Request $request, int $expense, ExpenseAttachmentService $attachments): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $found = $this->findOwnExpense($request, $expense);

        $request->validate([
            'attachments' => 'required|array|max:'.ExpenseAttachmentService::MAX_PER_EXPENSE,
            'attachments.*' => [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', ExpenseAttachmentService::ALLOWED_MIMES),
                'max:'.(ExpenseAttachmentService::MAX_BYTES / 1024),
            ],
        ]);

        $attachments->attach($found, $request->file('attachments'), $user->id);

        return response()->json(['data' => HubExpenseResource::make($found->refresh()->load(['subcategory.category', 'attachments']))]);
    }

    public function downloadAttachment(Request $request, int $expense, int $attachment): StreamedResponse
    {
        $found = $this->findOwnExpense($request, $expense);
        $att = $found->attachments()->findOrFail($attachment);

        return Storage::disk(ExpenseAttachmentService::disk())->download($att->path, $att->original_name);
    }

    public function destroyAttachment(Request $request, int $expense, int $attachment): JsonResponse
    {
        $found = $this->findOwnExpense($request, $expense);
        $att = $found->attachments()->findOrFail($attachment);

        Storage::disk(ExpenseAttachmentService::disk())->delete($att->path);
        $att->delete();

        return response()->json(['data' => HubExpenseResource::make($found->refresh()->load(['subcategory.category', 'attachments']))]);
    }

    /**
     * Gasto sobre el que el usuario puede actuar. Espeja la web: el
     * admin-sucursal puede editar/cancelar cualquier gasto de su sucursal
     * (Sucursal\GastoController valida solo branch_id); el cajero, solo los
     * suyos (Caja\GastoController valida además user_id).
     */
    private function findOwnExpense(Request $request, int $expense): Expense
    {
        $user = $request->user();

        return Expense::where('branch_id', $user->branch_id)
            ->when(! $user->hasRole('admin-sucursal'), fn ($q) => $q->where('user_id', $user->id))
            ->findOrFail($expense);
    }

    /**
     * Paridad con la web (Caja\GastoController::assertCajaCanMutate): el cajero
     * solo puede corregir/cancelar gastos de su turno ABIERTO; el admin-sucursal
     * puede actuar sobre cualquier gasto de su sucursal.
     */
    private function assertCanMutate(Request $request, Expense $expense): void
    {
        $user = $request->user();

        if ($user->hasRole('admin-sucursal')) {
            return;
        }

        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();

        abort_unless(
            $shift && $expense->cash_register_shift_id === $shift->id,
            403,
            'Solo puedes corregir tus gastos del turno abierto.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $isAdmin = $user->hasRole('admin-sucursal');
        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();

        // Paridad con la web: el cajero exige turno abierto (el gasto sale del
        // cajón); el admin-sucursal registra sin turno (Sucursal\GastoController
        // vía ExpenseWriter, sin cash_register_shift_id).
        if (! $isAdmin && ! $shift) {
            return response()->json(['message' => 'Abre un turno antes de registrar un gasto.'], 409);
        }

        $validated = $request->validate([
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')->where(
                    fn ($q) => $q->where('tenant_id', $user->tenant_id)->where('status', 'active')
                ),
            ],
            'description' => 'nullable|string|max:1000',
            'ai_draft_id' => 'nullable|integer',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        // Cajero: siempre efectivo y ligado al turno. Admin: elige método
        // (nullable, como la web) y el gasto no se ata a un turno.
        $expense = Expense::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'cash_register_shift_id' => $isAdmin ? null : $shift->id,
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'user_id' => $user->id,
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'payment_method' => $isAdmin
                ? ($validated['payment_method'] ?? null)
                : PaymentMethod::Cash->value,
            'expense_at' => now(),
            'description' => $validated['description'] ?? null,
        ]);

        app(AuditLogger::class)->logCreated($expense);

        // Si vino de la captura por IA, consume el draft: mueve sus adjuntos
        // (foto del ticket) al gasto y lo marca consumido.
        if (! empty($validated['ai_draft_id'])) {
            $this->consumeAiDraft($expense, (int) $validated['ai_draft_id'], $user->id, $user->tenant_id);
        }

        $expense->load(['subcategory.category', 'attachments']);

        return response()->json(['data' => HubExpenseResource::make($expense)], 201);
    }

    /**
     * Crea un borrador de gasto desde IA (texto/imagen/audio → GPT-4o/Whisper).
     * Síncrono; devuelve la propuesta para prerrellenar el formulario. NO crea
     * el gasto (eso ocurre al confirmar en store con el ai_draft_id). Reusa el
     * pipeline de la web (AiExpenseDraftService).
     */
    public function aiDraft(Request $request, AiExpenseDraftService $service): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $maxAudioKb = (int) (config('ai.expenses.max_audio_bytes', 10 * 1024 * 1024) / 1024);

        $validated = $request->validate([
            'input_text' => ['nullable', 'string', 'max:'.config('ai.expenses.max_input_text_length', 2000)],
            'attachments' => 'nullable|array|max:'.config('ai.expenses.max_images', 5),
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(ExpenseAttachmentService::MAX_BYTES / 1024)],
            'audio' => ['nullable', 'file', 'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac', 'max:'.$maxAudioKb],
        ]);

        $text = $validated['input_text'] ?? null;
        $files = $request->file('attachments') ?? [];
        $audio = $request->file('audio');

        if (trim((string) $text) === '' && $files === [] && $audio === null) {
            return response()->json(['message' => 'Aporta al menos un texto, una imagen o un audio para analizar.'], 422);
        }

        try {
            $draft = $service->createDraft($user->tenant, $user, $text, $files, $audio);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No pude analizar el gasto. Intenta de nuevo o captúralo manualmente.'], 502);
        }

        return response()->json([
            'draft_id' => $draft->id,
            'status' => $draft->status->value,
            'proposal' => $draft->parsed_proposal,
            'audio_transcription' => $draft->audio_transcription,
        ]);
    }

    private function consumeAiDraft(Expense $expense, int $draftId, int $userId, int $tenantId): void
    {
        $draft = AiExpenseDraft::query()
            ->where('id', $draftId)
            ->where('tenant_id', $tenantId)
            ->where('status', AiDraftStatus::Ready->value)
            ->first();

        if (! $draft) {
            return;
        }

        app(ExpenseAttachmentService::class)->attachFromDraft($expense, $draft, $userId);
        $draft->update([
            'status' => AiDraftStatus::Consumed->value,
            'expense_id' => $expense->id,
            'consumed_at' => now(),
        ]);
    }

    private function ensureModuleEnabled(?int $branchId): void
    {
        $branch = Branch::withoutGlobalScopes()->find($branchId);

        abort_unless(
            $branch && $branch->cashier_expenses_enabled,
            403,
            'El registro de gastos no está habilitado para tu sucursal.'
        );
    }
}
