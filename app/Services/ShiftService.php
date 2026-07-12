<?php

namespace App\Services;

use App\Exceptions\ShiftAlreadyOpenException;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Centraliza la apertura y cierre de turnos de caja para reusar entre el
 * controlador Inertia (Caja\TurnoController) y la API del hub.
 */
class ShiftService
{
    public function __construct(
        private ShiftTotalsCalculator $totals,
        private ShiftCashOutCalculator $cashOut,
    ) {}

    /** Turno abierto del usuario, o null. */
    public function current(User $user): ?CashRegisterShift
    {
        return CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * Abre un turno para el usuario.
     *
     * @throws ShiftAlreadyOpenException si ya hay uno abierto
     */
    public function open(User $user, float $openingAmount = 0): CashRegisterShift
    {
        if ($this->current($user) !== null) {
            throw new ShiftAlreadyOpenException;
        }

        return CashRegisterShift::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => $openingAmount,
        ]);
    }

    /**
     * Cierra el turno abierto del usuario y devuelve el shift cerrado.
     *
     * @param  array{declared_amount?: float|null, declared_card?: float|null, declared_transfer?: float|null, notes?: string|null}  $declared
     *
     * @throws ModelNotFoundException si no hay turno abierto
     */
    public function close(User $user, array $declared): CashRegisterShift
    {
        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $closingAt = now();
        $totals = $this->totals->compute($user->branch_id, $user->id, $shift->opened_at, $closingAt);

        $totalCash = $totals['total_cash'];
        $totalCard = $totals['total_card'];
        $totalTransfer = $totals['total_transfer'];
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $enabled = $this->enabledMethodsFor($user->branch_id);
        $withMovement = array_filter([
            'cash' => $totalCash > 0,
            'card' => $totalCard > 0,
            'transfer' => $totalTransfer > 0,
        ]);
        $effective = array_values(array_unique(array_merge($enabled, array_keys($withMovement))));
        if (! in_array('cash', $effective, true)) {
            $effective[] = 'cash';
        }

        $cashOutTotals = $this->cashOut->forShift($shift, $totalCash, $totalWithdrawals);
        $expectedCash = $cashOutTotals['expected_amount'];

        $declaredCash = in_array('cash', $effective, true)
            ? round((float) ($declared['declared_amount'] ?? 0), 2) : null;
        $declaredCard = in_array('card', $effective, true)
            ? round((float) ($declared['declared_card'] ?? 0), 2) : null;
        $declaredTransfer = in_array('transfer', $effective, true)
            ? round((float) ($declared['declared_transfer'] ?? 0), 2) : null;

        $diffCash = $declaredCash !== null ? round($declaredCash - $expectedCash, 2) : null;
        $diffCard = $declaredCard !== null ? round($declaredCard - $totalCard, 2) : null;
        $diffTransfer = $declaredTransfer !== null ? round($declaredTransfer - $totalTransfer, 2) : null;

        $shift->update([
            'closed_at' => $closingAt,
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
            'total_cash_provider_payments' => $cashOutTotals['cash_provider_payments'],
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $totals['collections_count'],
            'sales_generated_amount' => $totals['sales_generated_amount'],
            'sales_generated_count' => $totals['sales_generated_count'],
            'collections_from_today_amount' => $totals['collections_from_today_amount'],
            'collections_from_previous_amount' => $totals['collections_from_previous_amount'],
            'declared_amount' => $declaredCash,
            'declared_card' => $declaredCard,
            'declared_transfer' => $declaredTransfer,
            'expected_amount' => $expectedCash,
            'difference' => $diffCash ?? 0,
            'difference_card' => $diffCard ?? 0,
            'difference_transfer' => $diffTransfer ?? 0,
            'notes' => $declared['notes'] ?? null,
        ]);

        return $shift->refresh();
    }

    /**
     * Registra un retiro de efectivo sobre el turno abierto del usuario.
     *
     * @throws ModelNotFoundException si no hay turno abierto
     */
    public function addWithdrawal(User $user, float $amount, string $reason): CashWithdrawal
    {
        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        return CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'amount' => $amount,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Elimina un retiro aplicando las reglas compartidas web/hub: aislamiento
     * de tenant y sucursal para todos los roles; los admins pueden borrar
     * incluso con turno cerrado; el cajero dueño solo en su turno abierto.
     */
    public function removeWithdrawal(User $user, CashWithdrawal $withdrawal): void
    {
        // En web, TenantScope hace que el shift de otro tenant resuelva a null
        // y caiga en la primera guarda; en el hub (sin tenant bound) protegen
        // los checks explícitos de sucursal y tenant.
        $shift = $withdrawal->shift()->withoutGlobalScopes()->first();

        if (! $shift || $shift->branch_id !== $user->branch_id) {
            abort(403, 'Este retiro no pertenece a tu sucursal.');
        }

        if ($shift->tenant_id !== $user->tenant_id) {
            abort(403, 'Este retiro no pertenece a tu empresa.');
        }

        $isManager = $user->hasRole('admin-sucursal')
            || $user->hasRole('admin-empresa')
            || $user->hasRole('superadmin');

        $isOwnerOnOpenShift = $shift->user_id === $user->id
            && $shift->closed_at === null;

        if (! $isManager && ! $isOwnerOnOpenShift) {
            abort(403);
        }

        $withdrawal->delete();
    }

    /**
     * Resumen de conciliación de un turno para el hub. Funciona EN VIVO para un
     * turno abierto (recalcula con el reloj actual) y con los valores
     * persistidos para un turno cerrado (el corte). Incluye el esperado por
     * método, declarados/diferencias (solo si está cerrado) y el desglose de
     * salidas de efectivo (retiros, gastos, pagos a proveedor).
     *
     * @return array<string, mixed>
     */
    public function summary(CashRegisterShift $shift): array
    {
        $isOpen = $shift->closed_at === null;

        if ($isOpen) {
            $totals = $this->totals->compute($shift->branch_id, $shift->user_id, $shift->opened_at, now());
            $totalCash = $totals['total_cash'];
            $totalCard = $totals['total_card'];
            $totalTransfer = $totals['total_transfer'];
            $withdrawalsTotal = (float) $shift->withdrawals()->sum('amount');
            $cashOut = $this->cashOut->forShift($shift, $totalCash, $withdrawalsTotal);
            $expectedCash = $cashOut['expected_amount'];
            $cashExpenses = $cashOut['cash_expenses'];
            $cashProviderPayments = $cashOut['cash_provider_payments'];
            $saleCount = $totals['collections_count'];
            $previous = $totals['collections_from_previous_amount'];
        } else {
            $totalCash = (float) $shift->total_cash;
            $totalCard = (float) $shift->total_card;
            $totalTransfer = (float) $shift->total_transfer;
            $withdrawalsTotal = (float) $shift->withdrawals()->sum('amount');
            $expectedCash = (float) $shift->expected_amount;
            $cashExpenses = (float) $shift->total_cash_expenses;
            $cashProviderPayments = (float) $shift->total_cash_provider_payments;
            $saleCount = (int) $shift->sale_count;
            $previous = (float) $shift->collections_from_previous_amount;
        }

        $enabled = $this->enabledMethodsFor($shift->branch_id);
        $withMovement = array_keys(array_filter([
            'cash' => $totalCash > 0,
            'card' => $totalCard > 0,
            'transfer' => $totalTransfer > 0,
        ]));
        $effective = array_values(array_unique([...$enabled, ...$withMovement, 'cash']));
        $order = ['cash', 'card', 'transfer'];
        usort($effective, fn ($a, $b) => array_search($a, $order) <=> array_search($b, $order));

        $expectedByMethod = ['cash' => $expectedCash, 'card' => $totalCard, 'transfer' => $totalTransfer];
        $declaredByMethod = ['cash' => $shift->declared_amount, 'card' => $shift->declared_card, 'transfer' => $shift->declared_transfer];
        $diffByMethod = ['cash' => $shift->difference, 'card' => $shift->difference_card, 'transfer' => $shift->difference_transfer];

        $reconciliation = array_map(fn ($m) => [
            'method' => $m,
            'expected' => round((float) $expectedByMethod[$m], 2),
            'declared' => $isOpen || $declaredByMethod[$m] === null ? null : (float) $declaredByMethod[$m],
            'difference' => $isOpen || $diffByMethod[$m] === null ? null : (float) $diffByMethod[$m],
        ], $effective);

        return [
            'is_open' => $isOpen,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'cashier' => $shift->user?->name,
            'opening_amount' => (float) $shift->opening_amount,
            'totals' => ['cash' => $totalCash, 'card' => $totalCard, 'transfer' => $totalTransfer],
            'total_collected' => round($totalCash + $totalCard + $totalTransfer, 2),
            'collections_from_previous' => round($previous, 2),
            'sale_count' => $saleCount,
            'expected_cash' => round($expectedCash, 2),
            'cash_out' => [
                'withdrawals' => round($withdrawalsTotal, 2),
                'expenses' => round($cashExpenses, 2),
                'provider_payments' => round($cashProviderPayments, 2),
            ],
            'reconciliation' => $reconciliation,
            'difference_total' => $isOpen
                ? null
                : round((float) $shift->difference + (float) $shift->difference_card + (float) $shift->difference_transfer, 2),
            'enabled_methods' => $effective,
            'notes' => $shift->notes,
            'breakdown' => [
                'withdrawals' => $shift->withdrawals()->orderByDesc('created_at')->get()->map(fn ($w) => [
                    'id' => $w->id,
                    'amount' => (float) $w->amount,
                    'reason' => $w->reason,
                    'at' => $w->created_at?->toIso8601String(),
                ])->values(),
                'expenses' => $shift->cashExpenses()->orderByDesc('expense_at')->get()->map(fn ($e) => [
                    'id' => $e->id,
                    'concept' => $e->concept,
                    'amount' => (float) $e->amount,
                    'at' => $e->expense_at?->toIso8601String(),
                ])->values(),
                'provider_payments' => $shift->cashProviderPayments()->with('provider:id,name')->orderByDesc('paid_at')->get()->map(fn ($p) => [
                    'id' => $p->id,
                    'provider' => $p->provider?->name,
                    'amount' => (float) $p->amount,
                    'at' => $p->paid_at?->toIso8601String(),
                ])->values(),
            ],
        ];
    }

    /**
     * Métodos de pago habilitados en la sucursal.
     *
     * @return list<string>
     */
    private function enabledMethodsFor(int $branchId): array
    {
        $branch = Branch::withoutGlobalScopes()->find($branchId);

        return $branch
            ? $branch->enabledPaymentMethods()
            : Branch::SUPPORTED_PAYMENT_METHODS;
    }
}
