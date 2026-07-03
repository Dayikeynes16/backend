<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Enums\ProviderType;
use App\Models\AssistantDraft;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\Providers\ProviderWriter;
use Illuminate\Validation\Rule;

/**
 * Confirma un borrador de alta de proveedor. Re-valida con las MISMAS reglas que
 * el catálogo manual (unicidad de nombre por tenant) y crea vía {@see ProviderWriter}.
 */
final class ProviderDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly ProviderWriter $writer,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::Provider;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return ProviderWriter::canManage($user);
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;

        return [
            'name' => [
                'required', 'string', 'max:160',
                Rule::unique('providers', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'type' => ['required', Rule::enum(ProviderType::class)],
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            'rfc' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del proveedor.',
            'name.unique' => 'Ya existe un proveedor con ese nombre.',
            'type.required' => 'Selecciona el tipo de proveedor.',
            'email.email' => 'El correo no es válido.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $provider = $this->writer->create(app('tenant'), $user, $validated);

        $this->drafts->markConsumed($draft, $provider);

        return new DraftConfirmationResult(
            record: $provider,
            message: 'Proveedor creado.',
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'provider',
                'status' => 'consumed',
                'result_id' => $provider->id,
            ],
        );
    }
}
