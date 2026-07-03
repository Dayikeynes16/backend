<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\Expenses\ExpenseCategoryWriter;
use Illuminate\Validation\Rule;

/**
 * Confirma la edición de una categoría/subcategoría de gasto. Re-valida con las
 * mismas reglas del catálogo manual (unicidad de nombre) y aplica el cambio vía
 * {@see ExpenseCategoryWriter}. Gateado por el toggle de sucursal.
 */
final class ExpenseCategoryEditDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly ExpenseCategoryWriter $writer,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::ExpenseCategoryEdit;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return ExpenseCategoryWriter::canManage($user);
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;
        $targetType = request('target_type');
        $targetId = request('target_id');

        $rules = [
            'target_type' => ['required', 'in:categoria,subcategoria'],
            'target_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'description' => 'nullable|string|max:500',
            'status' => ['required', 'in:active,inactive'],
        ];

        if ($targetType === 'categoria') {
            $rules['target_id'][] = Rule::exists('expense_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId));
            $rules['name'][] = Rule::unique('expense_categories', 'name')
                ->ignore($targetId)
                ->where(fn ($q) => $q->where('tenant_id', $tenantId));
        } else {
            $rules['target_id'][] = Rule::exists('expense_subcategories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId));
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre.',
            'name.unique' => 'Ya existe una categoría de gastos con ese nombre.',
            'target_id.exists' => 'La categoría/subcategoría a editar no existe.',
            'status.in' => 'Estado inválido.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ];

        if ($validated['target_type'] === 'categoria') {
            $category = ExpenseCategory::query()->whereKey((int) $validated['target_id'])->firstOrFail();
            $record = $this->writer->updateCategory($category, $data);
            $message = 'Categoría actualizada.';
        } else {
            $subcategory = ExpenseSubcategory::query()->whereKey((int) $validated['target_id'])->firstOrFail();
            $record = $this->writer->updateSubcategory($subcategory, $data);
            $message = 'Subcategoría actualizada.';
        }

        $this->drafts->markConsumed($draft, $record);

        return new DraftConfirmationResult(
            record: $record,
            message: $message,
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'expense_category_edit',
                'status' => 'consumed',
                'result_id' => $record->id,
            ],
        );
    }
}
