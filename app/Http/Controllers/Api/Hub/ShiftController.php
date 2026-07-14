<?php

namespace App\Http\Controllers\Api\Hub;

use App\Exceptions\ShiftAlreadyOpenException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\ShiftResource;
use App\Models\CashRegisterShift;
use App\Models\User;
use App\Services\ShiftCashOutCalculator;
use App\Services\ShiftReportMessageService;
use App\Services\ShiftService;
use App\Services\ShiftTotalsCalculator;
use App\Services\ShiftVerdictService;
use App\Services\WhatsappMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function current(Request $request): JsonResponse
    {
        $shift = $this->shifts->current($request->user());

        return response()->json([
            'data' => $shift ? ShiftResource::make($shift)->resolve($request) : null,
            // Conciliación EN VIVO del turno abierto (esperado, totales por
            // método y salidas) para el panel del turno activo.
            'summary' => $shift ? $this->shifts->summary($shift) : null,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate(['opening_amount' => 'nullable|numeric|min:0']);

        try {
            $shift = $this->shifts->open($request->user(), (float) ($validated['opening_amount'] ?? 0));
        } catch (ShiftAlreadyOpenException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return ShiftResource::make($shift)->response()->setStatusCode(201);
    }

    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'declared_amount' => 'nullable|numeric|min:0',
            'declared_card' => 'nullable|numeric|min:0',
            'declared_transfer' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $shift = $this->shifts->close($request->user(), $validated);

        // El corte completo: shift cerrado + conciliación + veredicto neto +
        // link de WhatsApp al dueño (paridad con Caja\TurnoController::showCorte).
        return response()->json([
            'data' => ShiftResource::make($shift)->resolve($request),
            'summary' => $this->shifts->summary($shift),
            'verdict' => app(ShiftVerdictService::class)->build($shift),
            'whatsapp' => $this->whatsappPayload($shift, $request->user()),
        ]);
    }

    /**
     * Historial de cortes cerrados. El admin-sucursal ve todos los de su
     * sucursal; el cajero solo los suyos (misma regla que
     * CashShiftController::history de la web).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
        ]);

        $user = $request->user();
        $isAdmin = $this->isAdmin($user);

        $shifts = CashRegisterShift::where('branch_id', $user->branch_id)
            ->whereNotNull('closed_at')
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('opened_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('opened_at', '<=', $request->date('to')))
            ->with('user:id,name')
            ->orderByDesc('closed_at')
            ->paginate(15);

        return response()->json([
            'data' => collect($shifts->items())->map(fn (CashRegisterShift $s) => [
                'id' => $s->id,
                'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'closed_at' => $s->closed_at?->toIso8601String(),
                'opening_amount' => (float) $s->opening_amount,
                'total_sales' => (float) $s->total_sales,
                'sale_count' => (int) $s->sale_count,
                'expected_amount' => (float) $s->expected_amount,
                'declared_amount' => $s->declared_amount !== null ? (float) $s->declared_amount : null,
                // Suma con signo (neto del corte).
                'difference_total' => round((float) $s->difference + (float) $s->difference_card + (float) $s->difference_transfer, 2),
                // Descuadre por método: permite detectar cruces (falta en un
                // método, sobra en otro) que el neto ocultaría al verse "cuadrado".
                'difference' => (float) $s->difference,
                'difference_card' => (float) $s->difference_card,
                'difference_transfer' => (float) $s->difference_transfer,
                'has_discrepancy' => abs((float) $s->difference) > 0.005
                    || abs((float) $s->difference_card) > 0.005
                    || abs((float) $s->difference_transfer) > 0.005,
            ])->values(),
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'total' => $shifts->total(),
            ],
            'is_admin' => $isAdmin,
        ]);
    }

    /**
     * Detalle de un corte (persistente, re-visualizable). El admin puede ver
     * cualquiera de su sucursal; el cajero solo los propios (paridad con
     * Caja\TurnoController::showCorte y CashShiftController::show).
     */
    public function show(Request $request, int $shift): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $found = $this->findShift($request, $shift);

        abort_unless(
            $this->isAdmin($user) || $found->user_id === $user->id,
            403,
            'Este corte no es tuyo.'
        );

        return $this->cortePayload($found, $user);
    }

    /**
     * Recalcula los totales de un turno CERRADO (solo admin-sucursal).
     * Espeja CashShiftController::recalculate: respeta los declarados
     * originales (NULL no se resucita con 0) y recomputa diferencias.
     */
    public function recalculate(
        Request $request,
        int $shift,
        ShiftTotalsCalculator $calculator,
        ShiftCashOutCalculator $cashOut,
    ): JsonResponse {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user, 'No tienes permiso para recalcular cortes.');
        $found = $this->findShift($request, $shift);

        if (! $found->closed_at) {
            return response()->json(['message' => 'Solo se pueden recalcular turnos cerrados.'], 422);
        }

        $totals = $calculator->compute($found->branch_id, $found->user_id, $found->opened_at, $found->closed_at);

        $totalCash = $totals['total_cash'];
        $totalCard = $totals['total_card'];
        $totalTransfer = $totals['total_transfer'];
        $totalWithdrawals = (float) $found->withdrawals()->sum('amount');

        $cashOutTotals = $cashOut->forShift($found, $totalCash, $totalWithdrawals);
        $expected = $cashOutTotals['expected_amount'];

        $declaredCash = $found->declared_amount !== null ? (float) $found->declared_amount : null;
        $declaredCard = $found->declared_card !== null ? (float) $found->declared_card : null;
        $declaredTransfer = $found->declared_transfer !== null ? (float) $found->declared_transfer : null;

        $diffCash = $declaredCash !== null ? round($declaredCash - $expected, 2) : null;
        $diffCard = $declaredCard !== null ? round($declaredCard - $totalCard, 2) : null;
        $diffTransfer = $declaredTransfer !== null ? round($declaredTransfer - $totalTransfer, 2) : null;

        $found->update([
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
            'expected_amount' => $expected,
            'difference' => $diffCash ?? 0,
            'difference_card' => $diffCard ?? 0,
            'difference_transfer' => $diffTransfer ?? 0,
        ]);

        return $this->cortePayload($found->refresh(), $user);
    }

    /**
     * Reabre un turno cerrado (solo admin-sucursal). El cajero dueño no debe
     * tener otro turno abierto. Espeja CashShiftController::reopen.
     */
    public function reopen(Request $request, int $shift): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user, 'No tienes permiso para reabrir turnos.');
        $found = $this->findShift($request, $shift);

        if (! $found->closed_at) {
            return response()->json(['message' => 'Este turno ya está abierto.'], 422);
        }

        $hasOpenShift = CashRegisterShift::where('user_id', $found->user_id)
            ->whereNull('closed_at')
            ->exists();

        if ($hasOpenShift) {
            return response()->json(['message' => 'El cajero ya tiene un turno abierto. Debe cerrarlo primero.'], 422);
        }

        $found->update([
            'closed_at' => null,
            'total_cash' => 0,
            'total_card' => 0,
            'total_transfer' => 0,
            'total_sales' => 0,
            'sale_count' => 0,
            'declared_amount' => null,
            'declared_card' => null,
            'declared_transfer' => null,
            'expected_amount' => 0,
            'difference' => 0,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ]);

        return response()->json([
            'data' => ShiftResource::make($found->refresh())->resolve($request),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** Corte completo: shift + conciliación + veredicto + WhatsApp al dueño. */
    private function cortePayload(CashRegisterShift $shift, User $user): JsonResponse
    {
        return response()->json([
            'data' => ShiftResource::make($shift)->resolve(request()),
            'summary' => $this->shifts->summary($shift),
            'verdict' => $shift->closed_at ? app(ShiftVerdictService::class)->build($shift) : null,
            'whatsapp' => $this->whatsappPayload($shift, $user),
        ]);
    }

    /**
     * Link wa.me con el reporte del corte para el dueño (si el tenant tiene
     * owner_whatsapp y el turno está cerrado), como Caja\TurnoController.
     *
     * @return array{url: ?string, has_owner_whatsapp: bool}
     */
    private function whatsappPayload(CashRegisterShift $shift, User $user): array
    {
        $tenant = $user->tenant;
        $hasOwnerWhatsapp = ! empty($tenant->owner_whatsapp);
        $url = null;

        if ($hasOwnerWhatsapp && $shift->closed_at) {
            $text = app(ShiftReportMessageService::class)->buildShiftCloseText($shift);
            $url = app(WhatsappMessageService::class)->buildUrl($tenant->owner_whatsapp, $text);
        }

        return ['url' => $url, 'has_owner_whatsapp' => $hasOwnerWhatsapp];
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasRole('admin-sucursal') || $user->hasRole('superadmin');
    }

    private function ensureAdmin(User $user, string $message): void
    {
        abort_unless($this->isAdmin($user), 403, $message);
    }

    /** Turno de la sucursal del token; cross-branch → 404. */
    private function findShift(Request $request, int $shift): CashRegisterShift
    {
        return CashRegisterShift::where('branch_id', $request->user()->branch_id)
            ->findOrFail($shift);
    }
}
