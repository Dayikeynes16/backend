# Emparejar una báscula con un hub que no tiene API Key

- **Estado:** Diseño aprobado (2026-09-25), pendiente de plan.
- **Fecha:** 2026-09-25
- **Repos afectados:** `carniceria-saas` (backend, aditivo), `carniceria-hub`, `bascula-android`. `bascula` (Surface) y `hub-android`: sin cambios (§7).
- **Viene de:** un reporte real del 2026-09-25. Se reinstaló una tablet y el hub Windows, se entró al hub **sólo como cajero**, se autorizó la báscula en el hub (que la mostró conectada) y la tablet dijo **«Error del servidor (503)»** y no se conectó.

---

## 1. Qué pasó

1. El emparejamiento funciona: la báscula pide permiso, en el hub se autoriza y el hub le entrega su token (`GET /api/v1/pairing/{deviceId}`), **una sola vez** (`token_delivered_at`).
2. La báscula valida pidiendo la sucursal al hub (`GET /api/v1/branches/me`) **antes** de guardar el token (`SetupViewModel.saveHubAndEnter`).
3. El hub sirve eso desde **su copia del catálogo**, y esa copia la baja de la nube **con la API Key de la sucursal** (`BackendClient.fetchCatalog`, `X-Api-Key`). Nunca con la sesión.
4. Un hub recién instalado en el que sólo entró un cajero **no tiene API Key** (el campo vive en Configuración, sólo visible para admin-sucursal), así que no tiene catálogo y responde `503 «Hub sin catálogo…»` (`localApiServer.js`).
5. La báscula convierte cualquier código que no sea 401/429 en «Error del servidor (503)» (`Api.kt`) y **descarta el token**. El hub la da por autorizada; ella ya no puede recuperarlo sin que la revoquen en el hub.

Aparte, en la báscula «Desemparejar este hub» (`AdvancedSettingsScreen`) aparece aunque no haya ningún hub emparejado.

## 2. Decisiones

| # | Decisión | Por qué |
|---|----------|---------|
| D1 | **Sin API Key, el hub baja el catálogo con la sesión** abierta (cajero o admin-sucursal). | Reinstalar y entrar como cajero tiene que bastar para emparejar. Pedir un admin en cada reinstalación es justo lo que falló. |
| D2 | **La API Key, si existe, manda.** | Funciona sin nadie con sesión (el hub arranca oculto con Windows). La sesión es el respaldo, no al revés. |
| D3 | **El backend expone los mismos controladores de catálogo bajo la API del hub** (`/api/v1/hub/catalog/*`), no unos nuevos. | La respuesta es idéntica byte a byte a la Scale API: el hub la guarda y la sirve a las básculas sin traducir. Dos formatos se desincronizan. |
| D4 | **La báscula guarda el token en cuanto lo recibe**, antes de validar. | El token se entrega una sola vez. Validar primero y descartarlo si falla deja a la báscula huérfana por un problema que no es suyo. |
| D5 | **«Hub sin catálogo» no es un fallo de emparejamiento**: la báscula queda emparejada, lo dice claro y reintenta sola. | Es un estado transitorio del hub, no un rechazo. |
| D6 | **La báscula muestra el mensaje del hub**, no «Error del servidor (N)». | El hub ya decía qué pasaba; la báscula lo tapaba. |
| D7 | **«Desemparejar este hub» sólo si hay un hub emparejado.** | Un botón que no aplica confunde. |

## 3. Backend (aditivo)

- Rutas nuevas dentro del grupo `Route::prefix('v1/hub')->middleware(['auth:sanctum', 'hub.role'])`:

  | Método | Ruta | Controlador | Nombre |
  |---|---|---|---|
  | GET | `catalog/branch` | `Api\BranchController@me` | `api.hub.catalog.branch` |
  | GET | `catalog/categories` | `Api\CategoryController@index` | `api.hub.catalog.categories` |
  | GET | `catalog/products` | `Api\ProductController@index` | `api.hub.catalog.products` |

  con un middleware nuevo **`hub.catalog`** (`App\Http\Middleware\HubCatalogContext`) sólo en esas tres.
- `HubCatalogContext` hace, desde el usuario del token, lo mismo que `AuthenticateApiKey` hace desde la llave: resuelve su `Branch` (`withoutGlobalScope(TenantScope::class)` + `where('tenant_id', $user->tenant_id)`), comprueba sucursal y empresa activas (si no, 403 con el mismo mensaje), enlaza `app()->instance('tenant', $tenant)` y hace `$request->merge(['branch_id' => …, 'tenant_id' => …])` con las mismas claves que los controladores leen hoy. Usuario sin `branch_id` → 403.
- **Sin rate limit por API Key** (no hay llave); aplica el throttle normal del grupo del hub.
- La Scale API (`/api/v1/branches/me`, `categories`, `products`) **no cambia**.
- Docs: `docs/api/hub.md` (sección nueva «Catálogo») y `docs/arquitectura/ecosistema.md` (el hub puede alimentar su catálogo por cualquiera de las dos superficies).

## 4. Hub

### 4.1 De dónde sale el catálogo

`BackendClient.fetchCatalog()` elige la credencial:

1. **API Key** configurada → como hoy (`X-Api-Key`, rutas de la Scale API).
2. Si no, **token de sesión** (el del cajero/admin que entró) → `Authorization: Bearer`, rutas `/api/v1/hub/catalog/{branch,categories,products}` (misma paginación que `products`).
3. Si no hay ninguna → no pide nada y conserva lo último (como hoy sin backend).

`fetchCatalog()` devuelve además con qué se pidió (`via: 'apikey' | 'session' | null`) para que Dispositivo lo diga. El token se lee en cada intento (mismo criterio que las demás llamadas: puede aparecer o caducar con el hub abierto). Un 401 por sesión no borra el catálogo.

### 4.2 Cuándo se refresca

Además del intervalo y de los disparadores actuales: **al iniciar sesión** (login correcto) se llama a `refresher.refresh()` en el momento, sin esperar el siguiente ciclo. Así reinstalar + entrar deja el catálogo listo en segundos.

### 4.3 Decirlo

- Mientras **no haya catálogo** (`catalog.get('branch')` vacío), franja en **Inicio** y en **Básculas**: «Este hub todavía no tiene el catálogo de la sucursal: las básculas no pueden vender. Necesita internet y una sesión abierta (o la API Key en Configuración).» con botón **Reintentar** (`refreshCatalog`). Sólo el texto de la API Key se omite para el cajero.
- **Dispositivo** dice de dónde salió el último catálogo: «con la API Key» / «con la sesión de {nombre}».

### 4.4 El 503 dice qué es

Los dos `503` de `localApiServer.js` (catálogo y `branches/me`) añaden `code: 'no_catalog'` al cuerpo (**aditivo**; el `message` se conserva). Aplica a `hub-android` sólo si se quiere paridad de mensaje; no cambia `protocol_version` (§7).

## 5. Báscula Android

### 5.1 El token no se pierde

En `SetupViewModel.saveHubAndEnter`, al recibir `PairingOutcome.Approved`:

1. **Guardar primero** `configStore.saveHub(ServerCredentials(baseUrl, token), hubId)` y el nombre del equipo (y la llave propia si vino, como hoy).
2. Después validar con `ApiHelper.validateConnection(baseUrl, token)`:
   - `ok` → como hoy (entra a vender).
   - `failure = NO_CATALOG` (503 con `code: 'no_catalog'`) → **queda emparejada**: pantalla de espera «Emparejada con {hub}. El hub todavía no tiene el catálogo: pide que inicien sesión en el hub.» con **Reintentar** y reintento automático cada 15 s mientras la pantalla esté abierta; al conseguirlo entra a vender.
   - `UNAUTHORIZED` (401) → el token no sirve: se borra (`forgetHub()`) y se muestra el fallo, como hoy.
   - cualquier otro error (red, 5xx sin code) → queda emparejada con el mensaje del hub y Reintentar (no se descarta el token).

### 5.2 El mensaje del hub

`Api.kt` (`validateConnection` y el helper de la línea ~232): ante un `HttpException`, leer `message` y `code` del cuerpo JSON (`e.response()?.errorBody()`); si hay `message`, mostrarlo tal cual; `code == 'no_catalog'` → `ConnectionFailure.NO_CATALOG`. Sin cuerpo legible, el genérico de siempre.

### 5.3 Desemparejar sólo si hay hub

`AdvancedSettingsScreen`: la tarjeta «Hub» muestra «Desemparejar este hub» **sólo si** `config.hub` tiene credenciales. Si no, dice «Sin hub emparejado» (texto gris) y ninguna acción: la de buscar ya está en la tarjeta de arriba (`HubDiscoveryCard`). El estado se lee del `ConfigStore` en el ViewModel (`state.hubPaired`), refrescado al entrar a la pantalla.

## 6. Qué no hace

- **No** entrega a la báscula su propia llave de nube al emparejarse (lo que `bascula-android` ya espera desde `a91eba9`, «D» del diagnóstico): el hub no la manda todavía. Es el respaldo «si el hub se cae, la báscula vende sola» y va en su propio spec.
- **No** genera la API Key del hub automáticamente.
- **No** toca la Surface (`bascula`) ni `hub-android` (§7).

## 7. Compatibilidad

- **Scale API:** sin cambios. Básculas en producción no se enteran.
- **Protocolo LAN:** sólo un campo **aditivo** (`code`) en un cuerpo de error; `protocol_version` sigue en 1. Una báscula vieja sigue viendo el `message` (o su genérico). `hub-android` sigue funcionando con API Key; alimentar su catálogo con la sesión queda como siguiente paso (misma ruta del backend) y se anota en `ecosistema.md`.
- **Coordinación:** la rama `feat/desemparejar-de-verdad` del hub (otro trabajo en curso) toca `localApiServer.js`. El plan parte de `origin/main` y resuelve el choque al fusionar, sin tocar esa rama.

## 8. Pruebas

**Backend (PHPUnit)**
- Las tres rutas con token de cajero y de admin-sucursal devuelven **el mismo JSON** que la Scale API con la API Key de esa sucursal.
- Nunca datos de otra sucursal ni de otra empresa (dos tenants, dos sucursales).
- Sin token → 401; token de `admin-empresa` → 403 (`hub.role`); usuario sin sucursal → 403; sucursal inactiva → 403.

**Hub (Vitest)**
- `fetchCatalog`: con API Key usa `X-Api-Key` y la Scale API; sin llave y con token usa Bearer y `/hub/catalog/*`; sin nada no pide; devuelve `via`.
- Login correcto dispara `refresh()`.
- Los 503 llevan `code: 'no_catalog'`.

**Báscula Android (JUnit)**
- Aprobado + `no_catalog`: el token queda guardado y el estado es «emparejada, esperando catálogo».
- Aprobado + 401: el token se borra.
- El `message` del cuerpo llega al texto de error; sin cuerpo, el genérico.
- `hubPaired = false` oculta «Desemparejar este hub».

**A mano (Surface/tablet reales)**
1. Hub reinstalado, sin API Key, entrar como cajero → en segundos Inicio deja de mostrar la franja; emparejar una tablet funciona.
2. Hub sin catálogo (sin red al entrar) → la tablet queda «emparejada, esperando catálogo»; al volver la red y reintentar, entra.
3. Tablet recién instalada sin hub → Avanzada no ofrece desemparejar.

## 9. Documentación al implementar

`docs/api/hub.md`, `docs/arquitectura/ecosistema.md`, `carniceria-hub/docs/api-local.md` (el `code` del 503) y su doc de sincronización/catálogo, `bascula-android/README.md` (emparejamiento), y este spec → Implementado.
