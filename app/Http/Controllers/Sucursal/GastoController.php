<?php

namespace App\Http\Controllers\Sucursal;

use App\Enums\AiDraftStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\AiExpenseDraft;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Ai\AiExpenseDraftService;
use App\Services\AuditLogger;
use App\Services\ExpenseAttachmentService;
use App\Services\RecalculateClosedShifts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GastoController extends Controller
{
    public function __construct(
        private readonly ExpenseAttachmentService $attachments,
        private readonly AiExpenseDraftService $aiDrafts,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();
        $branchId = $user->branch_id;

        // Default filter: hoy
        $today = now()->toDateString();
        $from = $request->input('from') ?: ($request->has('to') ? null : $today);
        $to = $request->input('to') ?: ($request->has('from') ? null : $today);

        $query = Expense::query()
            ->where('branch_id', $branchId)
            ->with([
                'subcategory:id,expense_category_id,name',
                'subcategory.category:id,name',
                'branch:id,name',
                'user:id,name',
                'attachments:id,expense_id,original_name,mime_type,size_bytes',
                'history.user:id,name',
            ])
            ->when($request->expense_category_id, function ($q, $cat) {
                $q->whereHas('subcategory', fn ($sq) => $sq->where('expense_category_id', $cat));
            })
            ->when($request->expense_subcategory_id, fn ($q, $sub) => $q->where('expense_subcategory_id', $sub))
            ->when($request->payment_method, fn ($q, $pm) => $q->where('payment_method', $pm))
            ->when($from, fn ($q, $d) => $q->whereDate('expense_at', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('expense_at', '<=', $d))
            ->when($request->search, function ($q, $s) {
                $q->where(fn ($q2) => $q2
                    ->where('concept', 'ilike', "%{$s}%")
                    ->orWhere('description', 'ilike', "%{$s}%"));
            });

        $expenses = (clone $query)
            ->orderByDesc('expense_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $totals = [
            'amount' => (float) (clone $query)->sum('amount'),
            'count' => (clone $query)->count(),
        ];

        $categories = ExpenseCategory::with([
            'subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('name'),
        ])->where('status', 'active')->orderBy('name')->get(['id', 'name', 'description', 'aliases', 'status']);

        return Inertia::render('Sucursal/Gastos/Index', [
            'expenses' => $expenses,
            'totals' => $totals,
            'categories' => $categories,
            'paymentMethods' => $this->paymentMethodOptions(),
            'filters' => array_merge(
                $request->only('expense_category_id', 'expense_subcategory_id', 'payment_method', 'search'),
                ['from' => $from, 'to' => $to],
            ),
            'tenant' => $tenant,
        ]);
    }

    /**
     * Métodos de pago disponibles para gastos. Sólo cash/card/transfer
     * (los gastos a crédito no aplican al flujo de captura de comprobantes).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function paymentMethodOptions(): array
    {
        return collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
            ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $validated = $request->validate($this->validationRules($tenant->id, includeAiDraft: true), $this->messages());

        $draft = $this->resolveAiDraft($validated['ai_draft_id'] ?? null, $tenant->id);

        $expense = DB::transaction(function () use ($tenant, $user, $validated, $request, $draft) {
            $expense = Expense::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $user->branch_id,
                'expense_subcategory_id' => $validated['expense_subcategory_id'],
                'user_id' => $user->id,
                'concept' => $validated['concept'],
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'] ?? null,
                'expense_at' => $this->buildExpenseAt($validated['expense_date']),
                'description' => $validated['description'] ?? null,
            ]);

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

            return $expense;
        });

        app(AuditLogger::class)->logCreated($expense);

        return back()->with('success', 'Gasto registrado.');
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

    public function update(Request $request, Expense $gasto): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($gasto->tenant_id !== $tenant->id || $gasto->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate($this->validationRules($tenant->id), $this->messages());

        $auditor = app(AuditLogger::class);
        $before = $auditor->expenseSnapshot($gasto->loadMissing('subcategory', 'branch'));

        $gasto->update([
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? null,
            'expense_at' => $this->buildExpenseAt($validated['expense_date']),
            'description' => $validated['description'] ?? null,
            'updated_by' => $user->id,
        ]);

        if ($request->hasFile('attachments')) {
            $remaining = $gasto->attachments()->count();
            $incoming = count($request->file('attachments'));
            if ($remaining + $incoming > ExpenseAttachmentService::MAX_PER_EXPENSE) {
                return back()->withErrors([
                    'attachments' => 'Máximo '.ExpenseAttachmentService::MAX_PER_EXPENSE.' adjuntos por gasto.',
                ]);
            }
            $this->attachments->attach($gasto, $request->file('attachments'), $user->id);
        }

        $after = $auditor->expenseSnapshot($gasto->fresh()->loadMissing('subcategory', 'branch'));
        $auditor->logUpdatedIfChanged($gasto, $before, $after);
        $this->recalcShiftIfClosed($gasto);

        return back()->with('success', 'Gasto actualizado.');
    }

    public function destroy(Request $request, Expense $gasto): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($gasto->tenant_id !== $tenant->id || $gasto->branch_id !== $user->branch_id) {
            abort(403);
        }

        $reason = $request->validate([
            'cancellation_reason' => 'nullable|string|max:255',
        ])['cancellation_reason'] ?? null;

        $gasto->update([
            'cancelled_by' => $user->id,
            'cancellation_reason' => $reason,
        ]);
        $gasto->delete();

        app(AuditLogger::class)->logCancelled($gasto, $reason ?? '');
        $this->recalcShiftIfClosed($gasto);

        return back()->with('success', 'Gasto eliminado.');
    }

    /**
     * Si el gasto estaba ligado a un turno YA cerrado, su monto/baja cambia el
     * efectivo del corte → recalcula ese corte. Turno abierto no hace falta
     * (el corte suma en vivo al cerrar).
     */
    private function recalcShiftIfClosed(Expense $gasto): void
    {
        if (! $gasto->cash_register_shift_id) {
            return;
        }
        $shift = CashRegisterShift::find($gasto->cash_register_shift_id);
        if ($shift && $shift->closed_at) {
            app(RecalculateClosedShifts::class)->forShift($shift);
        }
    }

    private function validationRules(int $tenantId, bool $includeAiDraft = false): array
    {
        $rules = [
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active')),
            ],
            'expense_date' => 'required|date_format:Y-m-d|before_or_equal:'.now()->addDay()->toDateString(),
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'description' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:'.ExpenseAttachmentService::MAX_PER_EXPENSE,
            'attachments.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', ExpenseAttachmentService::ALLOWED_MIMES),
                'max:'.(ExpenseAttachmentService::MAX_BYTES / 1024),
            ],
        ];

        if ($includeAiDraft) {
            $rules['ai_draft_id'] = ['nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

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
            'expense_date.required' => 'Selecciona la fecha del gasto.',
            'expense_date.date_format' => 'Fecha inválida.',
            'expense_date.before_or_equal' => 'La fecha del gasto no puede ser futura.',
            'payment_method' => 'Método de pago inválido.',
        ];
    }

    private function buildExpenseAt(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.now()->format('H:i:s'));
    }
}
