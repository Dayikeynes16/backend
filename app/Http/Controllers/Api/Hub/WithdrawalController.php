<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Models\CashWithdrawal;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retiros de efectivo del turno abierto, para el hub de escritorio.
 * Reglas de negocio compartidas con la web vía ShiftService.
 */
class WithdrawalController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:255',
        ]);

        $withdrawal = $this->shifts->addWithdrawal(
            $request->user(),
            (float) $validated['amount'],
            $validated['reason'],
        );

        // Devuelve el resumen fresco para que el panel del turno se actualice
        // en un solo viaje (esperado en caja, cash_out y breakdown).
        $shift = $this->shifts->current($request->user());

        return response()->json([
            'data' => [
                'id' => $withdrawal->id,
                'amount' => (float) $withdrawal->amount,
                'reason' => $withdrawal->reason,
                'at' => $withdrawal->created_at?->toIso8601String(),
            ],
            'summary' => $shift ? $this->shifts->summary($shift) : null,
        ], 201);
    }

    public function destroy(Request $request, CashWithdrawal $withdrawal): JsonResponse
    {
        // Devuelve el resumen del turno AFECTADO por el retiro (no el del
        // usuario que borra, que puede ser un admin sin turno abierto).
        $shift = $this->shifts->removeWithdrawal($request->user(), $withdrawal);

        return response()->json([
            'summary' => $this->shifts->summary($shift),
        ]);
    }
}
