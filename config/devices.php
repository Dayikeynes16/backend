<?php

return [
    // Los mismos feeds que consumen los actualizadores de cada app.
    'releases_base_url' => env('DEVICES_RELEASES_BASE_URL', 'https://fls-a24d1ed0-1800-420a-8316-e0bc700348ff.laravel.cloud'),

    'feeds' => [
        'scale_windows' => ['path' => '/bascula/win/latest.yml', 'format' => 'yaml', 'key' => 'version'],
        'scale_android' => ['path' => '/android/latest.json', 'format' => 'json', 'key' => 'versionName'],
        'hub_windows' => ['path' => '/hub/win/latest.yml', 'format' => 'yaml', 'key' => 'version'],
        // hub_android: sin feed todavía.
    ],

    'online_minutes' => 10,
    'silence_minutes' => 30,
    'silence_window' => ['start' => '08:00', 'end' => '20:00'],
    'outdated_hours' => 24,
];
