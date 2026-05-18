<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Fuente única de verdad del branding de un tenant.
 *
 * Los colores y la imagen por defecto de productos viven en `settings` bajo
 * la key `branding:theme` como JSON. El logo se mantiene en la columna
 * `tenants.logo_path` (ya existe). Esto evita migraciones nuevas y deja la
 * puerta abierta a agregar más campos sin tocar schema.
 *
 * @phpstan-type BrandingTheme array{
 *   primary_color: string,
 *   accent_color: string,
 *   background_color: string,
 *   text_color: string,
 *   default_product_image_path: ?string,
 * }
 */
class BrandingService
{
    public const SETTING_KEY = 'branding:theme';

    /**
     * Defaults que reproducen el aspecto actual del menú público (rojo).
     * Cambiar estos defaults afecta a todos los tenants que no han
     * personalizado todavía — hacerlo con cuidado.
     *
     * @return BrandingTheme
     */
    public static function defaults(): array
    {
        return [
            'primary_color' => '#DC2626',
            'accent_color' => '#F59E0B',
            'background_color' => '#FFFFFF',
            'text_color' => 'auto',
            'default_product_image_path' => null,
        ];
    }

    /**
     * Devuelve el branding completo del tenant con defaults aplicados y URLs
     * resueltas. Es el método que debe consumir cualquier vista o API público.
     *
     * @return array{
     *   primary_color: string,
     *   accent_color: string,
     *   background_color: string,
     *   text_color: string,
     *   logo_path: ?string,
     *   logo_url: ?string,
     *   default_product_image_path: ?string,
     *   default_product_image_url: ?string,
     * }
     */
    public function forTenant(Tenant $tenant): array
    {
        $stored = $this->readTheme($tenant);
        $merged = array_merge(self::defaults(), $stored);

        return [
            'primary_color' => $merged['primary_color'],
            'accent_color' => $merged['accent_color'],
            'background_color' => $merged['background_color'],
            'text_color' => $merged['text_color'],
            'logo_path' => $tenant->logo_path,
            'logo_url' => $this->publicUrl($tenant->logo_path),
            'default_product_image_path' => $merged['default_product_image_path'],
            'default_product_image_url' => $this->publicUrl($merged['default_product_image_path']),
        ];
    }

    /**
     * Persiste cambios parciales al branding. Solo se aplican las llaves
     * presentes en $changes; el resto se preserva.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(Tenant $tenant, array $changes): void
    {
        $current = array_merge(self::defaults(), $this->readTheme($tenant));

        $themeKeys = ['primary_color', 'accent_color', 'background_color', 'text_color', 'default_product_image_path'];
        foreach ($themeKeys as $key) {
            if (array_key_exists($key, $changes)) {
                $current[$key] = $changes[$key];
            }
        }

        Setting::set(self::SETTING_KEY, $current, $tenant->id);

        if (array_key_exists('logo_path', $changes)) {
            $tenant->update(['logo_path' => $changes['logo_path']]);
        }
    }

    /**
     * Borra la personalización y los archivos asociados (logo + default image).
     * Devuelve los paths que estaban guardados, por si el caller necesita
     * limpiar archivos del storage.
     *
     * @return array{logo_path: ?string, default_product_image_path: ?string}
     */
    public function resetToDefaults(Tenant $tenant): array
    {
        $current = $this->forTenant($tenant);

        Setting::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', self::SETTING_KEY)
            ->delete();

        $tenant->update(['logo_path' => null]);

        return [
            'logo_path' => $current['logo_path'],
            'default_product_image_path' => $current['default_product_image_path'],
        ];
    }

    /**
     * Calcula el ratio de contraste WCAG 2.1 entre dos colores hex.
     * Devuelve un float entre 1.0 (sin contraste) y 21.0 (negro/blanco).
     * AA Normal exige >= 4.5; AA Large >= 3.0.
     */
    public static function contrastRatio(string $foregroundHex, string $backgroundHex): float
    {
        $fg = self::relativeLuminance($foregroundHex);
        $bg = self::relativeLuminance($backgroundHex);

        $lighter = max($fg, $bg);
        $darker = min($fg, $bg);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Devuelve '#000000' o '#FFFFFF' según cuál da mejor contraste con el fondo.
     */
    public static function autoTextColor(string $backgroundHex): string
    {
        return self::contrastRatio('#FFFFFF', $backgroundHex)
            >= self::contrastRatio('#000000', $backgroundHex)
            ? '#FFFFFF'
            : '#000000';
    }

    /**
     * @return BrandingTheme
     */
    private function readTheme(Tenant $tenant): array
    {
        $raw = Setting::get(self::SETTING_KEY, null, $tenant->id);

        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function publicUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return Storage::disk('public')->url($path);
        } catch (\Throwable) {
            return '/storage/'.ltrim($path, '/');
        }
    }

    private static function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $channel = fn (float $c): float => $c <= 0.03928
            ? $c / 12.92
            : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }
}
