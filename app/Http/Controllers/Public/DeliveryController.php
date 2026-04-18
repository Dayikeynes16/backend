<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\Public\OutOfRangeException;
use App\Exceptions\Public\QuoteUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\DeliveryFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function quote(Request $request, int $branch, DeliveryFeeService $service): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $tenant = app('tenant');

        $branchModel = Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('online_ordering_enabled', true)
            ->where('delivery_enabled', true)
            ->findOrFail($branch);

        try {
            $quote = $service->quote($branchModel, (float) $validated['lat'], (float) $validated['lng']);
        } catch (OutOfRangeException $e) {
            return response()->json([
                'error' => 'out_of_range',
                'message' => 'La dirección está fuera del rango de entrega.',
                'distance_km' => $e->distanceKm,
            ], 422);
        } catch (QuoteUnavailableException $e) {
            return response()->json([
                'error' => 'quote_unavailable',
                'message' => 'No se pudo calcular el costo de envío. Intenta de nuevo en unos minutos.',
            ], 503);
        }

        return response()->json($quote);
    }
}
