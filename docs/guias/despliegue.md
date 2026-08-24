# Despliegue

Cómo llega a producción cada pieza del ecosistema. Son dos caminos muy distintos: la web se despliega, y las tres aplicaciones cliente se **publican** y se auto-actualizan.

> **Alcance de este documento.** Recoge lo que está verificable en el repositorio: configuración, workflows y secrets. Los detalles que solo viven en el panel de Laravel Cloud (variables de entorno de producción, dominios, escalado) están marcados como **[confirmar en el panel]** — no se documentan de memoria.

## La web (`carniceria-saas`)

Se despliega en **Laravel Cloud**.

### Lo que Cloud aporta solo

`LARAVEL_CLOUD_DISK_CONFIG` — Cloud inyecta esta variable con los discos de la aplicación en JSON, y `config/filesystems.php` los registra al arrancar, sin necesitar el paquete `laravel/cloud`. Por eso en producción existe el disco `private` sin que nadie lo declare a mano.

### Variables que cambian respecto a local

| Variable | Local | Producción |
|----------|-------|------------|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | `production` / `false` |
| `FILESYSTEM_DISK` | `public` | `private` (lo fija Cloud) |
| `EXPENSES_DISK` | `local` | **`private`** |
| `REVERB_HOST` / `REVERB_SCHEME` | `localhost` / `http` | el dominio real / `https` **[confirmar en el panel]** |
| `OPENAI_API_KEY` | opcional | requerida, o el asistente responde `502` |
| `FEATURE_WEB_ORDERS` | `false` | `false` salvo que el cliente lo pida |

**`EXPENSES_DISK=private` es obligatorio en producción.** Es el disco donde viven tickets, facturas y comprobantes de transferencia. Con el valor por defecto (`local`) los adjuntos irían al disco efímero del contenedor: se pierden en el siguiente despliegue. Ver `config/expenses.php`, que documenta las tres configuraciones posibles.

El control de acceso a esos archivos no lo da el disco, sino `ExpenseAttachmentController`, que valida `tenant_id` y `branch_id` antes de servir el contenido. La subida además fuerza `visibility=private` como defensa en profundidad.

### Reverb en producción

El tiempo real necesita un proceso de WebSockets vivo, no solo el servidor web. **[confirmar en el panel]** cómo está desplegado y bajo qué dominio.

Lo que sí es seguro: las cuatro `VITE_REVERB_*` **se compilan dentro del bundle de JavaScript**. Si cambian, no basta con reiniciar: hay que **volver a compilar los assets** o los clientes seguirán intentando conectarse a la dirección anterior.

### Después de cada despliegue

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

Y si cambió algo del frontend, `npm run build`.

### Tareas programadas

`ai:expire-drafts` corre cada hora y limpia los borradores del asistente que caducaron. Requiere que el scheduler esté activo. **[confirmar en el panel]**

## Las tres aplicaciones cliente

Ninguna pasa por una tienda: se publican como artefacto y se auto-actualizan.

### El circuito, igual en las tres

```
  tag vX.Y.Z  →  GitHub Actions  →  pruebas  →  firma/empaquetado
                                                      ↓
                    Release de GitHub  ←  subida al bucket R2
                                                      ↓
                                  las instalaciones se actualizan solas
```

| App | Artefacto | Ruta en el bucket | Runbook |
|-----|-----------|-------------------|---------|
| `bascula` | Instalador NSIS | `bascula/win/` | `bascula/docs/releases.md` |
| `bascula-android` | APK firmado | `android/` | `bascula-android/docs/releases.md` |
| `carniceria-hub` | Instalador NSIS | `hub/win/` | — |

El bucket es **Cloudflare R2**, servido a través del dominio de archivos de Laravel Cloud. Los workflows suben con la CLI de `aws s3` apuntando al endpoint de R2.

### Secrets del repositorio

| Secret | Para qué | En qué repos |
|--------|----------|--------------|
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` | Subir el artefacto al bucket | los tres |
| `ANDROID_KEYSTORE_BASE64` | La llave de firma, en base64 | `bascula-android` |
| `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` | Credenciales de esa llave | `bascula-android` |

> **La llave de firma de Android es el activo más frágil del ecosistema.** Si se pierde, **ninguna tablet instalada puede actualizarse**: habría que desinstalar y reinstalar a mano en cada una. El workflow la restaura en el runner y la borra al terminar.

### De dónde sale la versión — no es igual en las tres

| App | La versión sale de | Si se olvida |
|-----|--------------------|--------------|
| `bascula-android` | **el tag** (`v1.2.3` → `1.2.3`, `versionCode 10203`) | nada: es automático |
| `bascula` | **`package.json`** | el CI publica **el número anterior** |
| `carniceria-hub` | **`package.json`** | el CI publica **el número anterior** |

En las dos de Electron hay que **subir la versión en `package.json` antes de crear el tag**. electron-builder la lee de ahí, no del tag.

### Trampas conocidas

**Android — instalar siempre desde la URL versionada.** Laravel Cloud reescribe el `Cache-Control` de los `.apk`, y la URL genérica puede servir una copia vieja.

**`bascula` — `perMachine: false`.** Se cambió a instalación por usuario para que el updater no pida permisos de administrador en cada actualización. Las Surface con la instalación antigua (por máquina) probablemente necesiten desinstalarla a mano una vez, o convivirán dos copias.

**Driver CH340 — solo en instalación nueva.** La macro del instalador va detrás de `${ifNot} ${isUpdated}`, porque `customInstall` también corre al actualizar y el driver pide elevación: sin esa guarda, cada auto-actualización mostraría un aviso de permisos.

**Hub — módulos nativos fuera del asar.** `better-sqlite3`, `serialport` y `assets/` se desempaquetan; dentro del asar no cargan y el fallo es silencioso.

## Verificar un despliegue

1. **Entrar** con una cuenta de cada rol y llegar a su panel.
2. **Tiempo real**: crear una venta contra la Scale API y comprobar que aparece sola en la mesa del cajero. Si no aparece, Reverb no está sirviendo.
3. **Adjuntos**: subir un comprobante y volver a descargarlo — verifica que `EXPENSES_DISK` apunta a un disco persistente.
4. **Básculas**: que una báscula real siga registrando ventas. Es la comprobación que no se puede simular.

## Ver también

- [entorno-local.md](entorno-local.md) — el entorno de desarrollo
- [ecosistema.md](../arquitectura/ecosistema.md) — cómo encajan las cuatro aplicaciones
