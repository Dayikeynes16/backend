<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload de imágenes de identidad del tenant (logo + imagen por defecto de
 * producto). A diferencia de adjuntos de gastos —que viven en disco privado—
 * estas imágenes se sirven al menú público sin auth, por lo que van al disco
 * `public`. La protección contra enumeración la da el UUID en el nombre.
 *
 * Path: tenants/{tenant_id}/branding/{kind}-{uuid}.{ext}
 */
class TenantImageService
{
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const KIND_LOGO = 'logo';

    public const KIND_DEFAULT_PRODUCT = 'default-product';

    public const MAX_LOGO_BYTES = 2 * 1024 * 1024;

    public const MAX_DEFAULT_PRODUCT_BYTES = 1024 * 1024;

    public function disk(): string
    {
        return 'public';
    }

    public function storeLogo(Tenant $tenant, UploadedFile $file): string
    {
        return $this->store($tenant, $file, self::KIND_LOGO);
    }

    public function storeDefaultProductImage(Tenant $tenant, UploadedFile $file): string
    {
        return $this->store($tenant, $file, self::KIND_DEFAULT_PRODUCT);
    }

    /**
     * Borra el archivo si existe. No falla si ya fue eliminado.
     */
    public function delete(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $storage = Storage::disk($this->disk());
        if ($storage->exists($path)) {
            $storage->delete($path);
        }
    }

    private function store(Tenant $tenant, UploadedFile $file, string $kind): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = $kind.'-'.Str::uuid()->toString().'.'.$ext;
        $directory = "tenants/{$tenant->id}/branding";

        $stored = $file->storeAs($directory, $filename, [
            'disk' => $this->disk(),
            'visibility' => 'public',
        ]);

        if (! $stored) {
            throw new \RuntimeException("No se pudo guardar la imagen ({$kind}) del tenant {$tenant->id}.");
        }

        return $stored;
    }
}
