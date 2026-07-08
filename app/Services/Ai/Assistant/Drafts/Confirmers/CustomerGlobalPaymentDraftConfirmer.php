<?php

namespace App\Services\Ai\Assistant\Drafts\Confirmers;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\AssistantDraftService;
use App\Services\Ai\Assistant\Drafts\DraftConfirmationResult;
use App\Services\Ai\Assistant\Drafts\DraftConfirmer;
use App\Services\CustomerGlobalPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Confirma un borrador de cobro global a cliente. Decisión D2: solo un usuario
 * con turno abierto puede confirmar, y el cliente debe pertenecer a la sucursal
 * de ese turno (el dinero entra a esa caja). La distribución FIFO NO se toma
 * del borrador: CustomerGlobalPaymentService::apply() la re-calcula de forma
 * autoritativa al confirmar (la deuda pudo cambiar desde el preview) — el
 * sobrepago con método distinto de efectivo se rechaza (422) y en efectivo el
 * excedente es cambio, como en una caja real.
 */
final class CustomerGlobalPaymentDraftConfirmer implements DraftConfirmer
{
    public function __construct(
        private readonly CustomerGlobalPaymentService $payments,
        private readonly AssistantDraftService $drafts,
    ) {}

    public function type(): AssistantDraftType
    {
        return AssistantDraftType::CustomerGlobalPayment;
    }

    public function authorize(User $user, AssistantDraft $draft): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        $tenantId = app('tenant')->id;

        $customerRule = Rule::exists('customers', 'id')->where(function ($q) use ($tenantId, $user) {
            $q->where('tenant_id', $tenantId);
            if (($user->hasRole('admin-sucursal') || $user->hasRole('cajero')) && $user->branch_id) {
                $q->where('branch_id', $user->branch_id);
            }
        });

        return [
            'customer_id' => ['required', 'integer', $customerRule],
            'amount_received' => 'required|numeric|gt:0|max:99999999.99',
            'method' => ['required', Rule::in(['cash', 'card', 'transfer'])],
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Selecciona el cliente que paga.',
            'customer_id.exists' => 'El cliente no es válido.',
            'amount_received.gt' => 'El monto recibido debe ser mayor a 0.',
            'method.in' => 'Método de pago no válido para cobros.',
        ];
    }

    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult
    {
        $customer = Customer::query()->whereKey((int) $validated['customer_id'])->firstOrFail();

        // D2: sin turno abierto no se cobra; el cobro entra a la caja del turno.
        $shift = CashRegisterShift::query()
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        if (! $shift) {
            abort(403, 'Debes tener un turno abierto para registrar cobros.');
        }
        if ($customer->branch_id !== $shift->branch_id) {
            abort(403, 'El cliente no pertenece a la sucursal de tu turno.');
        }

        $branch = Branch::query()->find($customer->branch_id);
        $enabled = $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'];
        if (! in_array($validated['method'], $enabled, true)) {
            throw ValidationException::withMessages([
                'method' => 'Ese método de pago no está habilitado en la sucursal.',
            ]);
        }

        try {
            $result = $this->payments->apply($customer, $user, [
                'amount_received' => (float) $validated['amount_received'],
                'method' => $validated['method'],
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (HttpException $e) {
            // 422 del servicio (sin saldo / sobrepago no-cash) → error de campo
            // para que la card siga editable en vez de marcarse no disponible.
            if ($e->getStatusCode() === 422) {
                throw ValidationException::withMessages(['amount_received' => $e->getMessage()]);
            }
            throw $e;
        }

        $cp = $result['customer_payment'];
        $this->drafts->markConsumed($draft, $cp);

        // Estamos dentro de la transacción del draft controller: el broadcast
        // (ShouldBroadcastNow) debe salir después del commit.
        $affectedSaleIds = $result['affected_sale_ids'];
        DB::afterCommit(fn () => $this->payments->broadcastSaleUpdates($affectedSaleIds));

        $applied = (float) $cp->amount_applied;
        $change = (float) $cp->change_given;
        $message = 'Cobro '.$cp->folio.' registrado: $'.number_format($applied, 2)
            .' aplicados a '.$cp->sales_affected_count.' venta(s)'
            .($change > 0 ? ', cambio $'.number_format($change, 2) : '').'.';

        return new DraftConfirmationResult(
            record: $cp,
            message: $message,
            card: [
                'draft_id' => $draft->id,
                'draft_type' => 'customer_global_payment',
                'status' => 'consumed',
                'result_id' => $cp->id,
                'applied' => $result['applied'],
                'change_given' => $change,
            ],
        );
    }
}
