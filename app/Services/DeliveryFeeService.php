<?php

namespace App\Services;

use App\Exceptions\Public\OutOfRangeException;
use App\Exceptions\Public\QuoteUnavailableException;
use App\Models\Branch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliveryFeeService
{
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array{distance_km: float, duration_min: float, tier_index: int, fee: float}
     */
    public function quote(Branch $branch, float $custLat, float $custLng): array
    {
        if ($branch->latitude === null || $branch->longitude === null) {
            throw new QuoteUnavailableException('Sucursal sin coordenadas configuradas.');
        }

        $maxKm = $branch->max_delivery_km !== null ? (float) $branch->max_delivery_km : null;

        if ($maxKm === null || $maxKm <= 0) {
            throw new QuoteUnavailableException('Sucursal sin tarifas de envío configuradas.');
        }

        $cacheKey = sprintf(
            'matrix:%s,%s:%s,%s',
            round((float) $branch->latitude, 4),
            round((float) $branch->longitude, 4),
            round($custLat, 4),
            round($custLng, 4)
        );

        $matrix = Cache::remember($cacheKey, now()->addHours(24), function () use ($branch, $custLat, $custLng) {
            return $this->fetchFromGoogle(
                (float) $branch->latitude, (float) $branch->longitude,
                $custLat, $custLng
            );
        });

        $distanceKm = $matrix['distance_km'];

        if ($distanceKm > $maxKm) {
            throw new OutOfRangeException($distanceKm);
        }

        $tier = $this->tierFee($branch->delivery_tiers ?? [], $distanceKm);

        if ($tier === null) {
            throw new OutOfRangeException($distanceKm);
        }

        return [
            'distance_km' => $distanceKm,
            'duration_min' => $matrix['duration_min'],
            'tier_index' => $tier['tier_index'],
            'fee' => $tier['fee'],
        ];
    }

    /**
     * @return array{tier_index: int, fee: float}|null
     */
    public function tierFee(array $tiers, float $distanceKm): ?array
    {
        $sorted = collect($tiers)
            ->values()
            ->sortBy('max_km')
            ->values();

        foreach ($sorted as $index => $tier) {
            if ((float) ($tier['max_km'] ?? 0) >= $distanceKm) {
                return [
                    'tier_index' => $index,
                    'fee' => (float) ($tier['fee'] ?? 0),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{distance_km: float, duration_min: float}
     */
    private function fetchFromGoogle(float $originLat, float $originLng, float $destLat, float $destLng): array
    {
        $apiKey = config('services.google_matrix.key');

        if (empty($apiKey)) {
            throw new QuoteUnavailableException('Google Matrix API key no configurada.');
        }

        try {
            $response = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => "{$originLat},{$originLng}",
                'destinations' => "{$destLat},{$destLng}",
                'mode' => 'driving',
                'units' => 'metric',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning('DeliveryFeeService: Google Matrix request failed', ['error' => $e->getMessage()]);
            throw new QuoteUnavailableException('No se pudo contactar al servicio de cotización.');
        }

        if (! $response->successful()) {
            Log::warning('DeliveryFeeService: Google Matrix HTTP error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new QuoteUnavailableException('Respuesta inválida del servicio de cotización.');
        }

        $json = $response->json();

        if (($json['status'] ?? null) !== 'OK') {
            Log::warning('DeliveryFeeService: Google Matrix non-OK status', ['body' => $json]);
            throw new QuoteUnavailableException('Google Matrix devolvió estado '.($json['status'] ?? 'desconocido'));
        }

        $element = $json['rows'][0]['elements'][0] ?? null;

        if (! $element || ($element['status'] ?? null) !== 'OK') {
            throw new QuoteUnavailableException('Ruta no disponible hasta la dirección indicada.');
        }

        return [
            'distance_km' => round(($element['distance']['value'] ?? 0) / 1000, 3),
            'duration_min' => round(($element['duration']['value'] ?? 0) / 60, 1),
        ];
    }
}
