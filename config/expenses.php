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
    | Configuración por entorno:
    |
    | - Local / Sail dev:
    |     EXPENSES_DISK=local  (default, storage/app/private)
    |
    | - Laravel Cloud:
    |     EXPENSES_DISK=private
    |   (Cloud auto-registra los disks via LARAVEL_CLOUD_DISK_CONFIG; el
    |   disk "private" debe existir como Disk privado en la app de Cloud.)
    |
    | - Stack manual con S3/R2 propio:
    |     EXPENSES_DISK=s3_private
    |   y configurar AWS_PRIVATE_* (ver config/filesystems.php).
    |
    | El control de acceso lo hace App\Http\Controllers\ExpenseAttachmentController
    | validando tenant_id y branch_id antes de servir el contenido. El upload
    | fuerza visibility=private como defensa en profundidad.
    */

    'disk' => env('EXPENSES_DISK', 'local'),
];
