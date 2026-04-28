<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GastoController extends Controller
{
    public function __construct(
        private readonly ExpenseAttachmentService $attachments,
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
            ])
            ->when($request->expense_category_id, function ($q, $cat) {
                $q->whereHas('subcategory', fn ($sq) => $sq->where('expense_category_id', $cat));
            })
            ->when($request->expense_subcategory_id, fn ($q, $sub) => $q->where('expense_subcategory_id', $sub))
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
        ])->where('status', 'active')->orderBy('name')->get(['id', 'name', 'status']);

        return Inertia::render('Sucursal/Gastos/Index', [
            'expenses' => $expenses,
            'totals' => $totals,
            'categories' => $categories,
            'filters' => array_merge(
                $request->only('expense_category_id', 'expense_subcategory_id', 'search'),
                ['from' => $from, 'to' => $to],
            ),
            'tenant' => $tenant,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $validated = $request->validate($this->validationRules($tenant->id), $this->messages());

        $expense = Expense::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $user->branch_id,
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'user_id' => $user->id,
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'expense_at' => $this->buildExpenseAt($validated['expense_date']),
            'description' => $validated['description'] ?? null,
        ]);

        if ($request->hasFile('attachments')) {
            $this->attachments->attach($expense, $request->file('attachments'), $user->id);
        }

        return back()->with('success', 'Gasto registrado.');
    }

    public function update(Request $request, Expense $gasto): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($gasto->tenant_id !== $tenant->id || $gasto->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate($this->validationRules($tenant->id), $this->messages());

        $gasto->update([
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
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

        return back()->with('success', 'Gasto eliminado.');
    }

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
            'expense_date' => 'required|date_format:Y-m-d|before_or_equal:'.now()->addDay()->toDateString(),
            'description' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:'.ExpenseAttachmentService::MAX_PER_EXPENSE,
            'attachments.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', ExpenseAttachmentService::ALLOWED_MIMES),
                'max:'.(ExpenseAttachmentService::MAX_BYTES / 1024),
            ],
        ];
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
        ];
    }

    private function buildExpenseAt(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.now()->format('H:i:s'));
    }
}
