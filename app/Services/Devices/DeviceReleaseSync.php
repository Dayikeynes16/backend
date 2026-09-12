<?php

namespace App\Services\Devices;

use App\Models\DeviceRelease;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee la última versión publicada de cada tipo de equipo de los feeds del
 * bucket (los mismos que consumen los actualizadores). Un feed caído no borra
 * lo que ya se sabía. `known_since` solo se mueve cuando cambia la versión:
 * es "desde cuándo se conoce esta versión", no "cuándo se consultó".
 *
 * @return array<string, string|null> versión por kind (null si falló)
 */
class DeviceReleaseSync
{
    public function run(): array
    {
        $base = rtrim(config('devices.releases_base_url'), '/');
        $result = [];

        foreach (config('devices.feeds') as $kind => $feed) {
            $version = $this->fetch($base.$feed['path'], $feed['format'], $feed['key']);
            $result[$kind] = $version;

            if ($version === null) {
                continue;
            }

            $current = DeviceRelease::find($kind);
            $now = now();

            if ($current === null) {
                DeviceRelease::create(['kind' => $kind, 'version' => $version, 'known_since' => $now, 'checked_at' => $now]);
            } elseif ($current->version !== $version) {
                $current->update(['version' => $version, 'known_since' => $now, 'checked_at' => $now]);
            } else {
                $current->update(['checked_at' => $now]);
            }
        }

        return $result;
    }

    private function fetch(string $url, string $format, string $key): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                Log::info('Feed de versiones no disponible', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            if ($format === 'json') {
                $value = $response->json($key);
            } else {
                // latest.yml de electron-builder: la primera línea "version: X.Y.Z".
                preg_match('/^'.preg_quote($key, '/').':\s*([^\s#]+)/m', $response->body(), $m);
                $value = $m[1] ?? null;
            }

            return is_string($value) && $value !== '' ? trim($value, "\"'") : null;
        } catch (Throwable $e) {
            Log::info('Feed de versiones con error', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
