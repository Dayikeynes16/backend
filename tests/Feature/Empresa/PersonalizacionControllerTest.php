<?php

namespace Tests\Feature\Empresa;

use App\Models\Setting;
use App\Models\Tenant;
use App\Services\BrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PersonalizacionControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_edit_returns_branding_with_defaults_for_new_tenant(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.personalizacion', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Empresa/Personalizacion')
                ->where('branding.primary_color', BrandingService::defaults()['primary_color'])
                ->where('branding.text_color', BrandingService::defaults()['text_color'])
                ->where('branding.logo_url', null)
                ->where('branding.default_product_image_url', null)
            );
    }

    public function test_admin_empresa_can_update_colors(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#1F2937',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $branding = app(BrandingService::class)->forTenant($this->tenant->fresh());
        $this->assertSame('#1F2937', $branding['primary_color']);
        $this->assertSame('#FACC15', $branding['accent_color']);
        $this->assertSame('auto', $branding['text_color']);
    }

    public function test_invalid_hex_is_rejected(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->from(route('empresa.personalizacion', $this->tenant->slug))
            ->post(route('empresa.personalizacion.update', $this->tenant->slug), [
                'primary_color' => 'red',
                'accent_color' => '#FACC15',
                'background_color' => '#FFFFFF',
                'text_color' => 'auto',
            ]);

        $response->assertSessionHasErrors(['primary_color']);
    }

    public function test_low_contrast_primary_is_rejected(): void
    {
        $this->actingAs($this->adminEmpresa);

        // Amarillo muy claro: texto blanco encima no se distingue.
        $response = $this->from(route('empresa.personalizacion', $this->tenant->slug))
            ->post(route('empresa.personalizacion.update', $this->tenant->slug), [
                'primary_color' => '#FFFF00',
                'accent_color' => '#000000',
                'background_color' => '#FFFFFF',
                'text_color' => 'auto',
            ]);

        $response->assertSessionHasErrors(['primary_color']);
    }

    public function test_low_contrast_text_on_background_is_rejected(): void
    {
        $this->actingAs($this->adminEmpresa);

        // Texto gris claro sobre fondo blanco — falla.
        $response = $this->from(route('empresa.personalizacion', $this->tenant->slug))
            ->post(route('empresa.personalizacion.update', $this->tenant->slug), [
                'primary_color' => '#DC2626',
                'accent_color' => '#FACC15',
                'background_color' => '#FFFFFF',
                'text_color' => '#DDDDDD',
            ]);

        $response->assertSessionHasErrors(['text_color']);
    }

    public function test_primary_equal_to_accent_is_rejected(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->from(route('empresa.personalizacion', $this->tenant->slug))
            ->post(route('empresa.personalizacion.update', $this->tenant->slug), [
                'primary_color' => '#DC2626',
                'accent_color' => '#dc2626',
                'background_color' => '#FFFFFF',
                'text_color' => 'auto',
            ]);

        $response->assertSessionHasErrors(['accent_color']);
    }

    public function test_logo_upload_replaces_previous_and_deletes_file(): void
    {
        Storage::fake('public');
        $this->actingAs($this->adminEmpresa);

        $first = UploadedFile::fake()->image('logo-v1.png', 400, 400);
        $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#DC2626',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
            'logo' => $first,
        ])->assertRedirect();

        $oldPath = $this->tenant->fresh()->logo_path;
        $this->assertNotNull($oldPath);
        Storage::disk('public')->assertExists($oldPath);

        $second = UploadedFile::fake()->image('logo-v2.png', 400, 400);
        $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#DC2626',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
            'logo' => $second,
        ])->assertRedirect();

        $newPath = $this->tenant->fresh()->logo_path;
        $this->assertNotNull($newPath);
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_logo_path_is_isolated_per_tenant(): void
    {
        Storage::fake('public');
        $this->actingAs($this->adminEmpresa);

        $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#DC2626',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertRedirect();

        $path = $this->tenant->fresh()->logo_path;
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/branding/", $path);
    }

    public function test_oversized_logo_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAs($this->adminEmpresa);

        $big = UploadedFile::fake()->image('huge.png', 800, 800)->size(5000); // 5 MB

        $response = $this->from(route('empresa.personalizacion', $this->tenant->slug))
            ->post(route('empresa.personalizacion.update', $this->tenant->slug), [
                'primary_color' => '#DC2626',
                'accent_color' => '#FACC15',
                'background_color' => '#FFFFFF',
                'text_color' => 'auto',
                'logo' => $big,
            ]);

        $response->assertSessionHasErrors(['logo']);
    }

    public function test_reset_clears_settings_and_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->adminEmpresa);

        $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#1F2937',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertRedirect();

        $logoPath = $this->tenant->fresh()->logo_path;
        $this->assertNotNull($logoPath);

        $response = $this->post(route('empresa.personalizacion.reset', $this->tenant->slug));
        $response->assertRedirect();

        $this->assertNull($this->tenant->fresh()->logo_path);
        Storage::disk('public')->assertMissing($logoPath);
        $this->assertSame(0, Setting::query()->where('tenant_id', $this->tenant->id)->where('key', BrandingService::SETTING_KEY)->count());

        $branding = app(BrandingService::class)->forTenant($this->tenant->fresh());
        $this->assertSame(BrandingService::defaults()['primary_color'], $branding['primary_color']);
    }

    public function test_admin_sucursal_cannot_update_branding(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#1F2937',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
        ]);

        $response->assertForbidden();
    }

    public function test_another_tenant_cannot_modify_my_branding(): void
    {
        // Crear otro tenant + admin-empresa de ese tenant
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-tenant', 'status' => 'active']);
        $otherAdmin = $this->makeUser('other@admin.test', 'admin-empresa', null);
        $otherAdmin->update(['tenant_id' => $otherTenant->id]);

        $this->actingAs($otherAdmin);

        // Intenta acceder con el slug de mi tenant — middleware ensure.tenant lo rechaza.
        $response = $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#1F2937',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
        ]);

        $response->assertForbidden();
        $this->assertSame(BrandingService::defaults()['primary_color'], app(BrandingService::class)->forTenant($this->tenant->fresh())['primary_color']);
    }

    public function test_public_api_returns_branding(): void
    {
        $this->post(route('empresa.personalizacion.update', $this->tenant->slug), [
            'primary_color' => '#1F2937',
            'accent_color' => '#FACC15',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
        ]);

        // Marcar sucursal con online_ordering_enabled para que aparezca en /api/public
        $this->branch->update(['online_ordering_enabled' => true]);

        $response = $this->getJson("/api/public/{$this->tenant->slug}");
        $response->assertOk();
        $response->assertJsonPath('branding.primary_color', BrandingService::defaults()['primary_color']);
        // Como en este test no autenticamos al admin, el update anterior fue 403 → defaults se mantienen.
    }

    public function test_contrast_ratio_helper_computes_expected_values(): void
    {
        // Negro sobre blanco = 21:1 (máximo).
        $this->assertEqualsWithDelta(21.0, BrandingService::contrastRatio('#000000', '#FFFFFF'), 0.01);
        // Blanco sobre blanco = 1:1 (mínimo).
        $this->assertEqualsWithDelta(1.0, BrandingService::contrastRatio('#FFFFFF', '#FFFFFF'), 0.01);
        // Rojo Tailwind 600 sobre blanco — debe ser ≥ 4.5 para texto blanco encima.
        $this->assertGreaterThanOrEqual(4.5, BrandingService::contrastRatio('#FFFFFF', '#DC2626'));
    }
}
