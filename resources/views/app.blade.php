<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        {{-- Outfit es la tipografía de titulares de la landing y no se usa en
             ninguna otra pantalla: cargarla siempre sería cobrarle una petición
             a cada cajero en cada turno para algo que solo ve quien visita la
             página pública. --}}
        @if (($page['component'] ?? null) === 'Welcome')
        <link href="https://fonts.bunny.net/css?family=outfit:600,700,800&display=swap" rel="stylesheet" />
        @endif

        <!-- Google Maps (for MapPicker in branch config) -->
        @if(config('services.google_matrix.key'))
        <script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_matrix.key') }}&libraries=geocoding&v=weekly" async defer></script>
        @endif

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
