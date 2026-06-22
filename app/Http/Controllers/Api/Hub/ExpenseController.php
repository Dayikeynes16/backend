<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubExpenseResource;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    /**
     * Gastos en efectivo del cajero (su turno) + árbol de categorías para el
     * formulario. Misma semántica que Caja\GastoController.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100']);

        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $search = trim((string) $request->input('search', ''));

        $baseQuery = Expense::where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->whereNull('cancelled_by')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('concept', 'ilike', "%{$search}%")
                ->orWhere('description', 'ilike', "%{$search}%")));

        // Total exacto sobre TODO el conjunto filtrado, no solo las 50 filas.
        $total = (float) (clone $baseQuery)->sum('amount');

        $expenses = (clone $baseQuery)
            ->with(['subcategory.category', 'attachments'])
            ->orderByDesc('expense_at')
            ->limit(50)
            ->get();

        $categories = ExpenseCategory::with([
            'subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('name'),
        ])->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        // Contexto del turno: total que sale del cajón afecta el corte.
        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();

        return response()->json([
            'data' => HubExpenseResource::collection($expenses),
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subcategories' => $c->subcategories->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ])->values(),
            'total' => $total,
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
        if ($found->cancelled_by !== null) {
            return response()->json(['message' => 'No se puede editar un gasto cancelado.'], 422);
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
        ]);

        $found->update([
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'description' => $validated['description'] ?? null,
            'updated_by' => $user->id,
        ]);

        return response()->json(['data' => HubExpenseResource::make($found->refresh()->load(['subcategory.category', 'attachments']))]);
    }

    public function destroy(Request $request, int $expense): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $found = $this->findOwnExpense($request, $expense);
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

    private function findOwnExpense(Request $request, int $expense): Expense
    {
        $user = $request->user();

        return Expense::where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->findOrFail($expense);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
        if (! $shift) {
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
        ]);

        // El gasto del cajero siempre es en efectivo y ligado al turno.
        $expense = Expense::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $shift->branch_id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'user_id' => $user->id,
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'payment_method' => PaymentMethod::Cash->value,
            'expense_at' => now(),
            'description' => $validated['description'] ?? null,
        ]);

        app(AuditLogger::class)->logCreated($expense);

        $expense->load('subcategory.category');

        return response()->json(['data' => HubExpenseResource::make($expense)], 201);
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
