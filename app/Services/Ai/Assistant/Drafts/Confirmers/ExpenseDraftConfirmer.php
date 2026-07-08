<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Enums\PaymentMethod;
use App\Models\AssistantDraft;
use App\Models\Branch;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\Expenses\ExpenseWriter;
use Illuminate\Validation\Rule;

/**
 * Confirma un borrador de gasto del asistente. Re-valida el payload editado con
 * las MISMAS reglas que GastoController y crea el gasto vía {@see ExpenseWriter}
 * (la lógica de dominio compartida). Fuerza la sucursal según el rol.
 */
final class ExpenseDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly ExpenseWriter $writer,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::Expense;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        // Cajero: su sucursal debe tener habilitado el toggle de gastos.
        if ($user->hasRole('cajero')) {
            return (bool) Branch::query()->find($user->branch_id)?->cashier_expenses_enabled;
        }

        return true;
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;

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
        ];

        // admin-sucursal y cajero no eligen sucursal: se fuerza la suya. admin-empresa sí.
        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('cajero')) {
            $rules['branch_id'] = [
                'required',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'expense_subcategory_id.required' => 'Selecciona una subcategoría.',
            'expense_subcategory_id.exists' => 'La subcategoría no es válida o está inactiva.',
            'branch_id.required' => 'Selecciona una sucursal.',
            'branch_id.exists' => 'La sucursal seleccionada no es válida.',
            'amount.min' => 'El monto debe ser mayor a 0.',
            'expense_date.required' => 'Selecciona la fecha del gasto.',
            'expense_date.date_format' => 'Fecha inválida.',
            'expense_date.before_or_equal' => 'La fecha del gasto no puede ser futura.',
            'payment_method' => 'Método de pago inválido.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $tenant = app('tenant');

        // La sucursal nunca se confía al cliente para admin-sucursal.
        $branchId = ($user->hasRole('admin-sucursal') || $user->hasRole('cajero'))
            ? $user->branch_id
            : (int) $validated['branch_id'];

        $expense = $this->writer->create(
            $tenant,
            $user,
            [
                'branch_id' => $branchId,
                'expense_subcategory_id' => (int) $validated['expense_subcategory_id'],
                'concept' => $validated['concept'],
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'] ?? null,
                'expense_at' => ExpenseWriter::buildExpenseAt($validated['expense_date']),
                'description' => $validated['description'] ?? null,
            ],
            draftAttachmentPaths: $draft->attachment_paths ?? [],
        );

        $this->drafts->markConsumed($draft, $expense);

        return new DraftConfirmationResult(
            record: $expense,
            message: 'Gasto registrado.',
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'expense',
                'status' => 'consumed',
                'result_id' => $expense->id,
            ],
        );
    }
}
