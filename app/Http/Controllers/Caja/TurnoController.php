<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Services\ShiftReportMessageService;
use App\Services\WhatsappMessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TurnoController extends Controller
{
    /**
     * Resuelve los métodos de pago habilitados para una sucursal.
     *
     * @return array<int,string>
     */
    private function enabledMethodsFor(?int $branchId): array
    {
        if (! $branchId) {
            return Branch::SUPPORTED_PAYMENT_METHODS;
        }

        $branch = Branch::find($branchId);

        return $branch
            ? $branch->enabledPaymentMethods()
            : Branch::SUPPORTED_PAYMENT_METHODS;
    }

    public function index(): Response
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            return Inertia::render('Caja/Turno/Open', [
                'tenant' => app('tenant'),
                'paymentMethods' => $this->enabledMethodsFor($user->branch_id),
            ]);
        }

        $payments = Payment::where('user_id', $user->id)
            ->where('created_at', '>=', $shift->opened_at)
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
        $expected = (float) $shift->opening_amount + $totalCash - $totalWithdrawals;

        return Inertia::render('Caja/Turno/Active', [
            'shift' => $shift->load('withdrawals'),
            'totals' => [
                'cash' => $totalCash,
                'card' => $totalCard,
                'transfer' => $totalTransfer,
                'total' => $totalCash + $totalCard + $totalTransfer,
                'withdrawals' => $totalWithdrawals,
                'expected_cash' => round($expected, 2),
                'payment_count' => $payments->pluck('sale_id')->unique()->count(),
            ],
            'paymentMethods' => $this->enabledMethodsFor($user->branch_id),
            'tenant' => app('tenant'),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $existing = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if ($existing) {
            return redirect()->route('caja.workbench', app('tenant')->slug);
        }

        $validated = $request->validate([
            'opening_amount' => 'nullable|numeric|min:0',
        ]);

        CashRegisterShift::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => $validated['opening_amount'] ?? 0,
        ]);

        return redirect()->route('caja.workbench', app('tenant')->slug)
            ->with('success', 'Turno abierto.');
    }

    public function close(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $payments = Payment::where('user_id', $user->id)
            ->where('created_at', '>=', $shift->opened_at)
            ->get();

        $totalCash = (float) $payments->where('method', 'cash')->sum('amount');
        $totalCard = (float) $payments->where('method', 'card')->sum('amount');
        $totalTransfer = (float) $payments->where('method', 'transfer')->sum('amount');
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        // Métodos efectivos: habilitados en la sucursal + los que tuvieron movimientos
        // en el turno (así no perdemos conciliación si se desactivó a mitad del turno).
        // 'cash' siempre se exige porque hay fondo inicial que cuadrar.
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

        $rules = ['notes' => 'nullable|string|max:500'];
        if (in_array('cash', $effective, true)) {
            $rules['declared_amount'] = 'required|numeric|min:0';
        }
        if (in_array('card', $effective, true)) {
            $rules['declared_card'] = 'required|numeric|min:0';
        }
        if (in_array('transfer', $effective, true)) {
            $rules['declared_transfer'] = 'required|numeric|min:0';
        }

        $validated = $request->validate($rules);

        $expectedCash = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);

        // declared_* queda NULL cuando el método no aplica (columna nullable).
        $declaredCash = array_key_exists('declared_amount', $validated)
            ? round((float) $validated['declared_amount'], 2) : null;
        $declaredCard = array_key_exists('declared_card', $validated)
            ? round((float) $validated['declared_card'], 2) : null;
        $declaredTransfer = array_key_exists('declared_transfer', $validated)
            ? round((float) $validated['declared_transfer'], 2) : null;

        $diffCash = $declaredCash !== null ? round($declaredCash - $expectedCash, 2) : null;
        $diffCard = $declaredCard !== null ? round($declaredCard - $totalCard, 2) : null;
        $diffTransfer = $declaredTransfer !== null ? round($declaredTransfer - $totalTransfer, 2) : null;

        $shift->update([
            'closed_at' => now(),
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $payments->pluck('sale_id')->unique()->count(),
            'declared_amount' => $declaredCash,
            'declared_card' => $declaredCard,
            'declared_transfer' => $declaredTransfer,
            'expected_amount' => $expectedCash,
            // difference_* no es nullable; se persiste 0 cuando no aplica.
            // El discriminador "no aplica vs cuadra" vive en declared_*.
            'difference' => $diffCash ?? 0,
            'difference_card' => $diffCard ?? 0,
            'difference_transfer' => $diffTransfer ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('caja.turno.corte', [app('tenant')->slug, $shift->id])
            ->with('success', 'Turno cerrado.')
            ->with('auto_open_whatsapp', true);
    }

    /**
     * Resumen del corte recién cerrado (o histórico propio) con botón de
     * reporte por WhatsApp al dueño. Solo el cajero dueño del shift puede
     * verlo; admins tienen su propia pantalla en panel Sucursal.
     */
    public function showCorte(
        CashRegisterShift $shift,
        ShiftReportMessageService $reportService,
        WhatsappMessageService $whatsappService,
    ): Response {
        $user = Auth::user();

        if ($shift->user_id !== $user->id) {
            abort(403, 'Este corte no es tuyo.');
        }
        if ($shift->tenant_id !== $user->tenant_id) {
            abort(403, 'Este corte no pertenece a tu empresa.');
        }
        if ($shift->branch_id !== $user->branch_id) {
            abort(403, 'Este corte no pertenece a tu sucursal.');
        }

        $shift->load(['user:id,name', 'withdrawals']);

        $tenant = app('tenant');
        $whatsappUrl = null;
        $hasOwnerWhatsapp = ! empty($tenant->owner_whatsapp);
        if ($hasOwnerWhatsapp && $shift->closed_at) {
            $text = $reportService->buildShiftCloseText($shift);
            $whatsappUrl = $whatsappService->buildUrl($tenant->owner_whatsapp, $text);
        }

        return Inertia::render('Caja/Turno/Corte', [
            'shift' => $shift,
            'tenant' => $tenant,
            'whatsappUrl' => $whatsappUrl,
            'hasOwnerWhatsapp' => $hasOwnerWhatsapp,
            'autoOpenWhatsapp' => (bool) session('auto_open_whatsapp', false),
        ]);
    }
}
