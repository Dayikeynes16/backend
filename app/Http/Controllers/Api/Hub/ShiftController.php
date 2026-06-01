<?php

namespace App\Http\Controllers\Api\Hub;

use App\Exceptions\ShiftAlreadyOpenException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\ShiftResource;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function current(Request $request): JsonResponse|JsonResource
    {
        $shift = $this->shifts->current($request->user());

        if ($shift === null) {
            return response()->json(['data' => null]);
        }

        return ShiftResource::make($shift);
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

    public function close(Request $request): JsonResource
    {
        $validated = $request->validate([
            'declared_amount' => 'nullable|numeric|min:0',
            'declared_card' => 'nullable|numeric|min:0',
            'declared_transfer' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $shift = $this->shifts->close($request->user(), $validated);

        return ShiftResource::make($shift);
    }
}
