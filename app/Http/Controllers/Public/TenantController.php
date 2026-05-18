<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\BrandingService;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    public function show(): JsonResponse
    {
        $tenant = app('tenant');

        $branches = $tenant->branches()
            ->where('status', 'active')
            ->where('online_ordering_enabled', true)
            ->get(['id', 'name', 'address', 'latitude', 'longitude', 'schedule', 'hours', 'pickup_enabled', 'delivery_enabled', 'min_order_amount'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'address' => $b->address,
                'latitude' => $b->latitude !== null ? (float) $b->latitude : null,
                'longitude' => $b->longitude !== null ? (float) $b->longitude : null,
                'schedule' => $b->schedule,
                'hours' => $b->hours,
                'pickup_enabled' => (bool) $b->pickup_enabled,
                'delivery_enabled' => (bool) $b->delivery_enabled,
                'min_order_amount' => $b->min_order_amount !== null ? (float) $b->min_order_amount : null,
            ])
            ->values();

        $branding = $this->branding->forTenant($tenant);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logo_path' => $tenant->logo_path,
            ],
            'branding' => [
                'primary_color' => $branding['primary_color'],
                'accent_color' => $branding['accent_color'],
                'background_color' => $branding['background_color'],
                'text_color' => $branding['text_color'],
                'logo_url' => $branding['logo_url'],
                'default_product_image_url' => $branding['default_product_image_url'],
            ],
            'branches' => $branches,
        ]);
    }
}
