<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBrandingRequest;
use App\Services\BrandingService;
use App\Services\TenantImageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PersonalizacionController extends Controller
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly TenantImageService $images,
    ) {}

    public function edit(): Response
    {
        $tenant = app('tenant');

        return Inertia::render('Empresa/Personalizacion', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'branding' => $this->branding->forTenant($tenant),
            'defaults' => BrandingService::defaults(),
        ]);
    }

    public function update(UpdateBrandingRequest $request): RedirectResponse
    {
        $tenant = app('tenant');
        $current = $this->branding->forTenant($tenant);

        $changes = [
            'primary_color' => strtoupper($request->string('primary_color')->toString()),
            'accent_color' => strtoupper($request->string('accent_color')->toString()),
            'background_color' => strtoupper($request->string('background_color')->toString()),
            'text_color' => $this->normalizeTextColor($request->string('text_color')->toString()),
        ];

        if ($request->boolean('remove_logo')) {
            $this->images->delete($current['logo_path']);
            $changes['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            $newPath = $this->images->storeLogo($tenant, $request->file('logo'));
            $this->images->delete($current['logo_path']);
            $changes['logo_path'] = $newPath;
        }

        if ($request->boolean('remove_default_product_image')) {
            $this->images->delete($current['default_product_image_path']);
            $changes['default_product_image_path'] = null;
        } elseif ($request->hasFile('default_product_image')) {
            $newPath = $this->images->storeDefaultProductImage($tenant, $request->file('default_product_image'));
            $this->images->delete($current['default_product_image_path']);
            $changes['default_product_image_path'] = $newPath;
        }

        $this->branding->update($tenant, $changes);

        return back()->with('success', 'Personalización actualizada.');
    }

    public function reset(): RedirectResponse
    {
        $tenant = app('tenant');

        $cleared = $this->branding->resetToDefaults($tenant);

        $this->images->delete($cleared['logo_path']);
        $this->images->delete($cleared['default_product_image_path']);

        return back()->with('success', 'Personalización restaurada a los valores por defecto.');
    }

    private function normalizeTextColor(string $value): string
    {
        return $value === 'auto' ? 'auto' : strtoupper($value);
    }
}
