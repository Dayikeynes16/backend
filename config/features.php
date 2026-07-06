<?php

/*
|--------------------------------------------------------------------------
| Feature flags globales de la aplicación
|--------------------------------------------------------------------------
| Apagan módulos completos (rutas + UI) sin borrar código ni datos.
| Ver docs/superpowers/specs/2026-07-06-ocultar-pedidos-web-design.md
*/

return [

    // Pedidos web / menú online público. OFF: la SPA /menu, la API pública,
    // el menú QR, Personalización y la vinculación pedido↔venta no se
    // registran; el panel oculta sus superficies. Reactivar: poner true
    // en .env + `sail artisan config:clear`.
    'web_orders' => env('FEATURE_WEB_ORDERS', false),

];
