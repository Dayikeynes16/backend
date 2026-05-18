<?php

namespace App\Http\Requests;

use App\Services\BrandingService;
use App\Services\TenantImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateBrandingRequest extends FormRequest
{
    private const HEX_REGEX = '/^#[0-9a-fA-F]{6}$/';

    private const MIN_CONTRAST_RATIO = 4.5;

    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && (
            $user->hasRole('admin-empresa')
            || $user->hasRole('superadmin')
        );
    }

    public function rules(): array
    {
        $hex = ['required', 'string', 'regex:'.self::HEX_REGEX];

        $logoMaxKb = (int) (TenantImageService::MAX_LOGO_BYTES / 1024);
        $imageMaxKb = (int) (TenantImageService::MAX_DEFAULT_PRODUCT_BYTES / 1024);

        return [
            'primary_color' => $hex,
            'accent_color' => $hex,
            'background_color' => $hex,
            'text_color' => ['required', 'string', 'regex:/^(auto|#[0-9a-fA-F]{6})$/'],

            'logo' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.$logoMaxKb],
            'remove_logo' => ['nullable', 'boolean'],

            'default_product_image' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.$imageMaxKb],
            'remove_default_product_image' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'primary_color.regex' => 'El color primario debe ser un hex válido (#RRGGBB).',
            'accent_color.regex' => 'El color de acento debe ser un hex válido (#RRGGBB).',
            'background_color.regex' => 'El color de fondo debe ser un hex válido (#RRGGBB).',
            'text_color.regex' => 'El color de texto debe ser "auto" o un hex válido (#RRGGBB).',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            $bg = $data['background_color'] ?? null;
            $primary = $data['primary_color'] ?? null;
            $accent = $data['accent_color'] ?? null;
            $text = $data['text_color'] ?? null;

            if (! $this->isHex($bg) || ! $this->isHex($primary) || ! $this->isHex($accent)) {
                return;
            }

            // Contraste texto sobre fondo. Si es "auto" el ratio siempre será ≥ 4.5.
            if ($text !== 'auto' && $this->isHex($text)) {
                $ratio = BrandingService::contrastRatio($text, $bg);
                if ($ratio < self::MIN_CONTRAST_RATIO) {
                    $validator->errors()->add(
                        'text_color',
                        sprintf('El contraste entre texto y fondo es muy bajo (%.2f:1). Mínimo: %.1f:1.', $ratio, self::MIN_CONTRAST_RATIO)
                    );
                }
            }

            // Botón primario: el menú dibuja texto blanco sobre primary, así que
            // exigimos contraste blanco-sobre-primary suficiente.
            $primaryRatio = BrandingService::contrastRatio('#FFFFFF', $primary);
            if ($primaryRatio < self::MIN_CONTRAST_RATIO) {
                $validator->errors()->add(
                    'primary_color',
                    sprintf('El color primario es muy claro: el texto blanco sobre él no se distingue (contraste %.2f:1).', $primaryRatio)
                );
            }

            // Primary vs accent no deben confundirse (umbral suave).
            if (strcasecmp($primary, $accent) === 0) {
                $validator->errors()->add('accent_color', 'El color de acento no puede ser igual al primario.');
            }
        });
    }

    private function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HEX_REGEX, $value) === 1;
    }
}
