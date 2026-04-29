<?php

/*
| Disks adicionales que Laravel Cloud inyecta vía la env LARAVEL_CLOUD_DISK_CONFIG
| (JSON con la lista de disks creados en la UI de Cloud). Los registramos aquí
| para que `Storage::disk('public')` o `Storage::disk('private')` funcionen sin
| requerir el package laravel/cloud.
*/
$cloudDisks = [];
$cloudConfig = env('LARAVEL_CLOUD_DISK_CONFIG');
if ($cloudConfig) {
    $parsed = json_decode($cloudConfig, true);
    if (is_array($parsed)) {
        foreach ($parsed as $cd) {
            if (! isset($cd['disk'])) {
                continue;
            }
            $name = $cd['disk'];
            $cloudDisks[$name] = [
                'driver' => 's3',
                'key' => $cd['access_key_id'] ?? null,
                'secret' => $cd['access_key_secret'] ?? null,
                'region' => $cd['default_region'] ?? 'auto',
                'bucket' => $cd['bucket'] ?? null,
                'url' => $cd['url'] ?? null,
                'endpoint' => $cd['endpoint'] ?? null,
                'use_path_style_endpoint' => $cd['use_path_style_endpoint'] ?? false,
                'visibility' => $name === 'public' ? 'public' : 'private',
                'throw' => false,
                'report' => false,
            ];
        }
    }
}

$baseDisks = [

    'local' => [
        'driver' => 'local',
        'root' => storage_path('app/private'),
        'serve' => true,
        'throw' => false,
        'report' => false,
    ],

    'public' => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
        'visibility' => 'public',
        'throw' => false,
        'report' => false,
    ],

    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'visibility' => 'public',
        'throw' => false,
        'report' => false,
    ],

    /*
    | Disco S3 privado manual (sin Laravel Cloud). En Laravel Cloud no se
    | usa: ahí los disks 'public'/'private' se inyectan automáticamente
    | desde LARAVEL_CLOUD_DISK_CONFIG. Útil si despliegas a otro stack.
    */
    's3_private' => [
        'driver' => 's3',
        'key' => env('AWS_PRIVATE_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
        'secret' => env('AWS_PRIVATE_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
        'region' => env('AWS_PRIVATE_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'bucket' => env('AWS_PRIVATE_BUCKET', env('AWS_BUCKET')),
        'url' => env('AWS_PRIVATE_URL'),
        'endpoint' => env('AWS_PRIVATE_ENDPOINT', env('AWS_ENDPOINT')),
        'use_path_style_endpoint' => env('AWS_PRIVATE_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
        'visibility' => 'private',
        'throw' => false,
        'report' => false,
    ],

];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Los disks de Laravel Cloud (de LARAVEL_CLOUD_DISK_CONFIG) sobrescriben
    | a los locales del mismo nombre. En local sin esa env, se usan los
    | locales/sail tal cual.
    */

    'disks' => array_merge($baseDisks, $cloudDisks),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
