<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\PhoneNormalizer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Confirma un borrador de alta de cliente. Branch forzado para
 * admin-sucursal/cajero; admin-empresa elige sucursal.
 */
final class CustomerDraftConfirmer implements DraftConfirmer
{
    public function __construct(private readonly AssistantDraftService $drafts) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::Customer;
    }

    /**
     * Cajero: su sucursal debe tener habilitado el toggle de clientes/cobros.
     * Se revalida aquí porque el borrador pudo prepararse antes de que la
     * empresa apagara el módulo.
     */
    public function authorize(User $user, AssistantDraft $draft): bool
    {
        if ($user->hasRole('cajero')) {
            return (bool) Branch::query()->find($user->branch_id)?->cashier_customers_enabled;
        }

        return true;
    }

    public function rules(User $user): array
    {
        $rules = [
            'name' => 'required|string|max:160',
            'phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
        ];

        if (! $user->hasRole('admin-sucursal') && ! $user->hasRole('cajero')) {
            $rules['branch_id'] = [
                'required', 'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', app('tenant')->id)),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El cliente necesita nombre.',
            'branch_id.required' => 'Selecciona la sucursal del cliente.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $branchId = ($user->hasRole('admin-sucursal') || $user->hasRole('cajero'))
            ? (int) $user->branch_id
            : (int) $validated['branch_id'];

        // El teléfono es único por sucursal. Sin este chequeo, `create` choca
        // contra `customers_tenant_branch_phone_uniq` y el usuario recibe un
        // 500 en vez de saber que el cliente ya existe. Se compara normalizado
        // porque el mutator del modelo guarda en E.164.
        $phone = PhoneNormalizer::normalize($validated['phone'] ?? null);

        if ($phone !== null) {
            $existing = Customer::withoutGlobalScopes()
                ->where('tenant_id', app('tenant')->id)
                ->where('branch_id', $branchId)
                ->where('phone', $phone)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'phone' => 'Ya existe un cliente con ese teléfono en la sucursal: '.$existing->name.'.',
                ]);
            }
        }

        $customer = Customer::create([
            'tenant_id' => app('tenant')->id,
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'phone' => $phone,
            'notes' => $validated['notes'] ?? null,
            'status' => 'active',
        ]);

        $this->drafts->markConsumed($draft, $customer);

        return new DraftConfirmationResult(
            record: $customer,
            message: 'Cliente "'.$customer->name.'" creado correctamente.',
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'customer',
                'status' => 'consumed',
                'result_id' => $customer->id,
            ],
        );
    }
}
