<?php

namespace App\Http\Controllers\Api\Hub;

use App\Exceptions\ShiftAlreadyOpenException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\ShiftResource;
use App\Services\ShiftService;
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

        // El corte: shift cerrado + resumen de conciliación con desglose de salidas.
        return response()->json([
            'data' => ShiftResource::make($shift)->resolve($request),
            'summary' => $this->shifts->summary($shift),
        ]);
    }
}
