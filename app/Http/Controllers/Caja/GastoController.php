<?php

namespace App\Http\Controllers\Caja;

use App\Enums\AiDraftStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\AiExpenseDraft;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gastos en efectivo del cajero, ligados al turno abierto.
 * Sale del cajón → descuenta del efectivo esperado del corte.
 * El módulo se habilita/deshabilita por sucursal desde el panel de empresa.
 * Soporta captura con IA y adjuntos (foto del ticket), igual que el panel
 * de sucursal, pero el método de pago queda fijo en efectivo.
 */
class GastoController extends Controller
{
    public function __construct(
        private readonly ExpenseAttachmentService $attachments,
    ) {}

    /**
     * Listado de los gastos que el cajero ha registrado (solo los suyos),
     * sin filtros de fecha. Registrar exige turno abierto.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $this->ensureModuleEnabled($user->branch_id);

        $query = Expense::query()
            ->where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->with([
                'subcategory:id,expense_category_id,name',
                'subcategory.category:id,name',
                'attachments:id,expense_id,original_name,mime_type,size_bytes',
                'history.user:id,name',
                'branch:id,name',
                'user:id,name',
            ])
            ->when($request->search, function ($q, $s) {
                $q->where(fn ($q2) => $q2
                    ->where('concept', 'ilike', "%{$s}%")
                    ->orWhere('description', 'ilike', "%{$s}%"));
            });

        $expenses = (clone $query)
            ->orderByDesc('expense_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Expense $e) {
                $shift = CashRegisterShift::where('user_id', Auth::id())->whereNull('closed_at')->first();
                $e->setAttribute('can_manage', $shift && $e->cash_register_shift_id === $shift->id);

                return $e;
            });

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        $categories = ExpenseCategory::with([
            'subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('name'),
        ])->where('status', 'active')->orderBy('name')->get(['id', 'name', 'description', 'aliases', 'status']);

        return Inertia::render('Caja/Gastos/Index', [
            'expenses' => $expenses,
            'totals' => [
                'amount' => (float) (clone $query)->sum('amount'),
                'count' => (clone $query)->count(),
            ],
            'categories' => $categories,
            'hasOpenShift' => $hasOpenShift,
            'filters' => $request->only('search'),
            'tenant' => app('tenant'),
        ]);
    }

    /**
     * Aborta si la sucursal del cajero tiene deshabilitado el módulo de gastos.
     */
    private function ensureModuleEnabled(?int $branchId): void
    {
        $branch = $branchId ? Branch::find($branchId) : null;

        if (! $branch || ! $branch->cashier_expenses_enabled) {
            abort(403, 'El registro de gastos no está habilitado para tu sucursal.');
        }
    }

    private function assertCajaCanMutate(Expense $gasto): CashRegisterShift
    {
        if ($gasto->tenant_id !== app('tenant')->id) {
            abort(404);
        }
        $shift = CashRegisterShift::where('user_id', Auth::id())->whereNull('closed_at')->first();
        if (! $shift
            || $gasto->user_id !== Auth::id()
            || $gasto->cash_register_shift_id !== $shift->id) {
            abort(403, 'Solo puedes corregir tus gastos del turno abierto.');
        }

        return $shift;
    }

    public function update(Request $request, Expense $gasto): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($gasto);

        $validated = $request->validate([
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', app('tenant')->id)->where('status', 'active')),
            ],
            'description' => 'nullable|string|max:1000',
        ]);

        $auditor = app(AuditLogger::class);
        $before = $auditor->expenseSnapshot($gasto->loadMissing('subcategory', 'branch'));

        $gasto->update([
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'description' => $validated['description'] ?? null,
            'updated_by' => Auth::id(),
        ]);

        $after = $auditor->expenseSnapshot($gasto->fresh()->loadMissing('subcategory', 'branch'));
        $auditor->logUpdatedIfChanged($gasto, $before, $after);

        return back()->with('success', 'Gasto actualizado.');
    }

    public function destroy(Request $request, Expense $gasto): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($gasto);

        $reason = $request->validate([
            'cancellation_reason' => 'nullable|string|max:255',
        ])['cancellation_reason'] ?? null;

        $gasto->update(['cancelled_by' => Auth::id(), 'cancellation_reason' => $reason]);
        $gasto->delete();
        app(AuditLogger::class)->logCancelled($gasto, $reason ?? '');

        return back()->with('success', 'Gasto eliminado.');
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $this->ensureModuleEnabled($user->branch_id);

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            abort(422, 'Abre tu turno antes de registrar un gasto.');
        }

        $validated = $request->validate($this->validationRules($tenant->id), $this->messages());

        $draft = $this->resolveAiDraft($request->input('ai_draft_id'), $tenant->id);

        DB::transaction(function () use ($tenant, $user, $shift, $validated, $request, $draft) {
            // El gasto del cajero siempre es en efectivo y ligado al turno:
            // sale del cajón y afecta el corte. El método de pago y la fecha
            // del form se ignoran a propósito (se fijan a efectivo y "ahora").
            $expense = Expense::create([
                'tenant_id' => $tenant->id,
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

            if ($request->hasFile('attachments')) {
                $this->attachments->attach($expense, $request->file('attachments'), $user->id);
            }

            if ($draft) {
                $this->attachments->attachFromDraft($expense, $draft, $user->id);
                $draft->update([
                    'status' => AiDraftStatus::Consumed->value,
                    'expense_id' => $expense->id,
                    'consumed_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Gasto en efectivo registrado.');
    }

    private function resolveAiDraft(?int $draftId, int $tenantId): ?AiExpenseDraft
    {
        if (! $draftId) {
            return null;
        }

        return AiExpenseDraft::where('id', $draftId)
            ->where('tenant_id', $tenantId)
            ->where('status', AiDraftStatus::Ready->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(int $tenantId): array
    {
        return [
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active')),
            ],
            'description' => 'nullable|string|max:1000',
            'ai_draft_id' => ['nullable', 'integer', 'min:1'],
            'attachments' => 'nullable|array|max:'.ExpenseAttachmentService::MAX_PER_EXPENSE,
            'attachments.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', ExpenseAttachmentService::ALLOWED_MIMES),
                'max:'.(ExpenseAttachmentService::MAX_BYTES / 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'expense_subcategory_id.required' => 'Selecciona una subcategoría.',
            'expense_subcategory_id.exists' => 'La subcategoría no es válida o está inactiva.',
            'amount.min' => 'El monto debe ser mayor a 0.',
            'attachments.max' => 'Máximo '.ExpenseAttachmentService::MAX_PER_EXPENSE.' adjuntos por gasto.',
            'attachments.*.mimes' => 'Solo se permiten imágenes (jpg, png, webp) o PDF.',
            'attachments.*.mimetypes' => 'Tipo de archivo no permitido.',
            'attachments.*.max' => 'Cada archivo no puede superar 5 MB.',
        ];
    }
}
