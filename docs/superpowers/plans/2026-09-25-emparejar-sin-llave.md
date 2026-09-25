# Emparejar con un hub Windows recién instalado — Plan de implementación

> **For agentic workers:** un subagente por repo (A, B, C son independientes y corren en paralelo). TDD: cada comportamiento con su test antes del código. Steps con checkbox.

**Goal:** que un hub Windows en el que sólo entró un cajero tenga catálogo y entregue a cada báscula su llave de nube; que la báscula no pierda el token ni esconda el motivo; que revocar en el hub revoque también la llave de nube.

**Spec:** `carniceria-saas/docs/superpowers/specs/2026-09-25-emparejar-sin-llave-design.md` (manda sobre este plan). Referencia de comportamiento: `hub-android` `origin/main` (`core/.../sesion/LlaveDeLaSucursal.kt`, `LlaveDeLaBascula.kt`, `ServidorLocal.kt` ~l.150-175).

## Global Constraints

- Commits con línea final `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>` tras línea en blanco. **No push.**
- Todo cambio de protocolo LAN es **aditivo**: `protocol_version` sigue en 1.
- Rutas del backend usadas por el hub: `POST /api/v1/hub/devices/{deviceId}/api-key` (existe; body `{name}`; 201 `{raw_key, device_id, rotated}`) y `DELETE /api/v1/hub/devices/{deviceId}/api-key` (nueva, Parte A; 200 `{revoked: N}`).
- Textos de UI en español; comentarios en español explicando el porqué.

---

## Parte A — Backend (`/Users/sebas/Documents/version 2/.worktrees/saas-emparejar`, rama `docs/emparejar-sin-llave`)

PHP sólo por Docker: `W=/private/tmp/claude-501/-Users-sebas-Documents-version-2-carniceria-saas/51a6364d-29ce-4ecc-82e7-22b2b88f31a6/scratchpad/emp-php.sh` → `$W php artisan test --compact [--filter=X]`, `$W ./vendor/bin/pint --dirty`.

- [ ] **A1 Test** `tests/Feature/Hub/DeviceApiKeyRevokeTest.php` (mira el test existente de `deviceApiKey` para el setup de usuarios/tokens Sanctum y reutilízalo): cajero y admin-sucursal borran las `ApiKey` con ese `device_id` de **su** sucursal y reciben `{revoked: N}`; no se tocan llaves sin `device_id`, de otro `device_id`, de otra sucursal ni de otra empresa; segunda llamada `{revoked: 0}` 200; sin token 401; admin-empresa 403; la llave revocada recibe 401 en `GET /api/v1/branches/me` con `X-Api-Key`.
- [ ] **A2 Implementar** en `routes/api.php` junto a `devices/{deviceId}/api-key`: `Route::delete('devices/{deviceId}/api-key', [HubConfigController::class, 'revokeDeviceApiKey'])->name('api.hub.devices.api-key.revoke');` y el método en `Api/Hub/ConfigController.php` (mismo estilo que `deviceApiKey`: `ApiKey::withoutGlobalScopes()->where('branch_id', $user->branch_id)->where('device_id', trim($deviceId))->delete()`), con su docblock (por qué la puede pedir un cajero, idempotencia).
- [ ] **A3** `docs/api/hub.md`: fila de la ruta junto a la de crear. Suite completa verde, pint, commit `feat(hub): revocar la llave de nube de un equipo, también desde el cajero`.

## Parte B — Hub Windows (`/Users/sebas/Documents/version 2/.worktrees/hub-llaves`, rama `feat/llaves-de-equipo`)

Comandos: `npx vitest run` (línea base 1385), `npm run build:renderer`. Cuidado con los tests-guardia (`nucleoSinPlataforma`, `preloadBridge`): sólo `index.js`/`ipc.js` importan `electron`; canales nuevos del preload deben registrarse donde el guardia los busca.

- [ ] **B1 Cliente de la nube**: en `src/main/api/` (mismo molde que `NotificationsApi`/`ConfigApi`) métodos `deviceApiKey(deviceId, name)` → `POST /api/v1/hub/devices/{id}/api-key` y `revokeDeviceApiKey(deviceId)` → `DELETE`. Tests como `test/api-*.test.js`.
- [ ] **B2 Llave propia** (`src/main/sync/ownApiKey.js`, port de `LlaveDeLaSucursal`): `ensureOwnApiKey({ getApiKey, setApiKey, hubId, hubName, api, onSaved })` → `'ready' | 'not_allowed' | 'failed'` (spec §4.1: si ya hay, no pide; 201+raw_key guarda y llama `onSaved` que dispara `refresher.refresh()`; 403 `not_allowed`; resto `failed`). Tests puros. Cablear tras login correcto y tras restaurar sesión al arrancar (busca dónde `ipc.js`/`index.js` manejan `authLogin`/`authSession`), sin bloquear el login.
- [ ] **B3 Llave de cada báscula**: columna `api_key TEXT` y `cloud_key_revoke_pending INTEGER DEFAULT 0` en la tabla local de dispositivos (sigue el patrón de migraciones SQLite del repo). En `hub:devices:approve`, tras aprobar: pedir `deviceApiKey(deviceId, nombre)` con la sesión → guardar; si falla, la aprobación queda y el IPC devuelve `{ ok: true, cloudKey: 'saved' | { error: motivo } }` con los tres textos del spec §4.2. IPC nuevo `hub:devices:retryCloudKey(deviceId)` (sólo si el token no se entregó). Tests.
- [ ] **B4 Acuse**: en `localApiServer.js`, rama `approved && !token_delivered_at`: añadir `api_key` y `cloud_url` (`config.backendUrl`) **sólo si** la fila tiene llave; marcar entregado y **borrar** `api_key` de la fila. Los dos `503` añaden `code: 'no_catalog'`. Tests del servidor local (hay tests existentes del pairing: extiéndelos). Si `scripts/conformidad.mjs` tiene casos de pairing, que sigan pasando.
- [ ] **B5 Revocar** (`hub:devices:revoke`): tras revocar local, borrar `api_key` de la fila y llamar `revokeDeviceApiKey`; si no hay sesión/red/5xx → `cloud_key_revoke_pending = 1` sin bloquear. Reintento: función `retryPendingCloudRevokes()` llamada en `onOnline` y tras login. Al aprobar una fila con marca pendiente: primero `DELETE`, luego `POST`. Tests.
- [ ] **B6 UI**: franja «Este hub todavía no tiene el catálogo…» con Reintentar (llama llave propia + `refreshCatalog`) en Inicio y Básculas mientras no haya catálogo (expón por IPC/`hub:status` si hay catálogo de sucursal); en Básculas, por fila: «Sin respaldo en la nube: {motivo}» + Reintentar, y «Llave de nube: pendiente de revocar (sin conexión)»; diálogo de revocar: «La báscula dejará de poder conectarse a este hub y a la nube.». Build sin errores.
- [ ] **B7 Docs**: `docs/api-local.md` (acuse con `api_key`/`cloud_url`, `code` del 503), doc de catálogo/sincronización si describe la API Key. Commits por pieza con mensajes `feat(llaves): …` / `fix(emparejar): …`.

## Parte C — Báscula Android (`/Users/sebas/Documents/version 2/.worktrees/bascula-emparejar`, rama `fix/emparejar-sin-perder-token`)

Gradle **siempre** con `JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home`: `./gradlew testDebugUnitTest` y `./gradlew assembleDebug`.

- [ ] **C1 Mensaje del hub** (`data/Api.kt`): ante `HttpException`, leer `message` y `code` del cuerpo JSON de error; mostrar `message` si existe; `code == "no_catalog"` → `ConnectionFailure.NO_CATALOG` (valor nuevo). Extraer el parseo a una función pura testeable (JUnit).
- [ ] **C2 Token primero** (`ui/setup/SetupViewModel.saveHubAndEnter`): guardar hub + nombre + llave de nube (si vino) **antes** de validar; `ok` → igual; `NO_CATALOG` → estado «emparejada, esperando catálogo» con texto del spec §5.1, Reintentar y reintento cada 15 s mientras la pantalla esté abierta (se cancela al salir); `UNAUTHORIZED` → `configStore.forgetHub()` + fallo; otro → queda emparejada con el mensaje y Reintentar. Ajusta el diálogo/estado de emparejamiento (`PairingStage`) para mostrarlo. Tests del ViewModel o de la lógica extraída.
- [ ] **C3 `HubStatus.NO_CATALOG`** (`data/ConnectionState.kt`, la sonda en `ConnectionViewModel`/`HubLocator`): 503 con `code no_catalog` → `NO_CATALOG`, no `UNREACHABLE`; en `ConnectionScreen` subtítulo «El hub todavía no tiene el catálogo · pide que inicien sesión en el hub», tarjeta no seleccionable. `ServerSelector` no cambia (test: `NO_CATALOG` no es sano). Cubre todos los `when` exhaustivos sobre `HubStatus`.
- [ ] **C4 Desemparejar sólo con hub** (`ui/setup/AdvancedSettingsScreen.kt` + su ViewModel): `state.hubPaired` desde `ConfigStore` al entrar; sin hub → «Sin hub emparejado» y sin botón.
- [ ] **C5**: `README.md` (emparejamiento: token primero, llave de nube, sin catálogo). `testDebugUnitTest` y `assembleDebug` verdes. Commits por pieza.
