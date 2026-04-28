<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Disk para adjuntos de gastos
    |--------------------------------------------------------------------------
    |
    | Almacenamiento de tickets/facturas. Debe ser un disco PRIVADO (sin URLs
    | públicas) y, en producción, persistente entre deploys.
    |
    | - Local/Sail dev: 'local' (storage/app/private) ✅ por defecto.
    | - Laravel Cloud / multi-instance: configurar EXPENSES_DISK a un disco
    |   S3 privado dedicado (ver config/filesystems.php). El disco 'local'
    |   por sí solo NO es persistente en Cloud — los archivos se pierden al
    |   redeploy.
    |
    | El control de acceso a los archivos lo hace el controller
    | (App\Http\Controllers\ExpenseAttachmentController) validando tenant_id
    | y branch_id antes de servir el contenido. La visibilidad del disco
    | sigue siendo PRIVADA por defecto.
    */

    'disk' => env('EXPENSES_DISK', 'local'),
];
