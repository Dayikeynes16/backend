<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <meta name="theme-color" content="#dc2626" />
    <title>Menú</title>
    @if(config('services.google_matrix.key'))
    <script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_matrix.key') }}&libraries=geocoding,marker&v=weekly" async defer></script>
    @endif
    @vite(['resources/js/public/main.js'])
</head>
<body class="bg-gray-50 antialiased">
    <div id="public-app"></div>
</body>
</html>
