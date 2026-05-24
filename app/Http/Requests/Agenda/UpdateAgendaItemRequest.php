<?php

namespace App\Http\Requests\Agenda;

use App\Enums\AgendaItemType;
use App\Enums\AgendaPriority;
use App\Enums\AgendaRecurrence;
use App\Enums\AgendaScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgendaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('item'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app('tenant')->id;

        return [
            'type' => ['required', Rule::enum(AgendaItemType::class)],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000'],
            'scope' => ['required', Rule::enum(AgendaScope::class)],
            'branch_id' => [
                Rule::requiredIf(fn () => $this->input('scope') === AgendaScope::Branch->value),
                'nullable',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'assigned_to_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
            ],
            'starts_at' => [
                Rule::requiredIf(fn () => $this->input('type') === AgendaItemType::Event->value),
                'nullable', 'date',
            ],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['boolean'],
            'remind_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::enum(AgendaPriority::class)],
            'recurrence' => ['nullable', Rule::enum(AgendaRecurrence::class)],
            'recurrence_until' => ['nullable', 'date'],
        ];
    }
}
