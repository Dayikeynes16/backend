<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\Expenses\ExpenseCategoryWriter;
use Illuminate\Validation\Rule;

/**
 * Confirma un borrador de categoría/subcategoría de gasto. Re-valida con las
 * mismas reglas del catálogo manual (unicidad de nombre por tenant) y crea vía
 * {@see ExpenseCategoryWriter}. Gateado por el toggle de sucursal.
 */
final class ExpenseCategoryDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly ExpenseCategoryWriter $writer,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::ExpenseCategory;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return ExpenseCategoryWriter::canManage($user);
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;
        $tipo = request('tipo');

        $rules = [
            'tipo' => ['required', 'in:categoria,subcategoria'],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => 'nullable|string|max:500',
        ];

        if ($tipo === 'categoria') {
            $rules['nombre'][] = Rule::unique('expense_categories', 'name')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId));
        } elseif ($tipo === 'subcategoria') {
            $rules['existing_category_id'] = [
                'required',
                Rule::exists('expense_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre.',
            'nombre.unique' => 'Ya existe una categoría de gastos con ese nombre.',
            'existing_category_id.required' => 'Selecciona la categoría padre.',
            'existing_category_id.exists' => 'La categoría padre no existe.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $tenant = app('tenant');

        if ($validated['tipo'] === 'categoria') {
            $record = $this->writer->createCategory($tenant, $user, [
                'name' => $validated['nombre'],
                'description' => $validated['descripcion'] ?? null,
            ]);
            $message = 'Categoría creada.';
        } else {
            $category = ExpenseCategory::query()->whereKey((int) $validated['existing_category_id'])->firstOrFail();
            $record = $this->writer->createSubcategory($tenant, $user, $category, [
                'name' => $validated['nombre'],
                'description' => $validated['descripcion'] ?? null,
            ]);
            $message = 'Subcategoría creada.';
        }

        $this->drafts->markConsumed($draft, $record);

        return new DraftConfirmationResult(
            record: $record,
            message: $message,
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'expense_category',
                'status' => 'consumed',
                'result_id' => $record->id,
            ],
        );
    }
}
