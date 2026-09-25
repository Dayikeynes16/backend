# Emparejar una báscula con un hub Windows recién instalado

- **Estado:** Diseño aprobado (2026-09-25), pendiente de plan.
- **Fecha:** 2026-09-25
- **Repos afectados:** `carniceria-hub` (hub Windows), `bascula-android` y `carniceria-saas` (**una ruta aditiva**, §4.4; lo demás existe desde el 2026-09-21). `hub-android`: sin cambios en este spec — es la referencia; adoptar la revocación de §4.4 queda pendiente para él. `bascula` (Surface): sin cambios.
- **Viene de:** un reporte real del 2026-09-25. Se reinstaló una tablet y el hub Windows, se entró al hub **sólo como cajero**, se autorizó la báscula en el hub (que la mostró conectada) y la tablet dijo **«Error del servidor (503)»** y no se conectó.

---

## 1. Qué pasó

1. El emparejamiento funciona: la báscula pide permiso, en el hub se autoriza y el hub le entrega su **token local** (`GET /api/v1/pairing/{deviceId}`), **una sola vez** (`token_delivered_at`).
2. La báscula valida pidiendo la sucursal al hub (`GET /api/v1/branches/me`) **antes** de guardar el token (`SetupViewModel.saveHubAndEnter`).
3. El hub sirve eso desde **su copia del catálogo**, que baja de la nube **con su API Key** (`BackendClient.fetchCatalog`, `X-Api-Key`).
4. Un hub recién instalado en el que sólo entró un cajero **no tiene API Key**, así que no tiene catálogo y responde `503 «Hub sin catálogo…»` (`localApiServer.js`).
5. La báscula convierte ese 503 en «Error del servidor (503)» (`Api.kt`) y **descarta el token**. El hub la da por autorizada; ella ya no puede recuperarlo sin que la revoquen.

Y una causa más de fondo: **el hub Windows se quedó atrás** en un cambio que el resto del ecosistema ya hizo el 2026-09-21:

| Pieza | Estado |
|---|---|
| Backend `POST /api/v1/hub/devices/{deviceId}/api-key` — la llave **de un equipo**, la puede pedir **un cajero**, idempotente por equipo (rota la anterior) | ✅ `2e201b9`, documentado en `docs/api/hub.md` |
| `hub-android`: al entrar alguien, pide **su propia** llave (`LlaveDeLaSucursal`); al **aprobar** una báscula pide **la de ella** y se la entrega con el token (`LlaveDeLaBascula`, `ServidorLocal`) | ✅ |
| `bascula-android`: recibe `api_key` + `cloud_url` en el acuse del emparejamiento y los guarda como su credencial de nube | ✅ `a91eba9` |
| **Hub Windows** | ❌ ni pide su llave ni la de las básculas |

Consecuencias: el hub Windows sin admin nunca tiene catálogo (el 503), y ninguna báscula emparejada con él tiene **llave de nube propia**, así que si el hub se apaga no puede pasarse a la nube.

Aparte, en la báscula «Desemparejar este hub» (`AdvancedSettingsScreen`) aparece aunque no haya ningún hub emparejado.

## 2. Dos llaves distintas

| | Token local del hub | Llave de nube (API Key, `X-Api-Key`) |
|---|---|---|
| Lo emite | El hub, al autorizar | El backend |
| Sirve para | Hablar **con el hub** por la LAN | Vender **directo a la nube** |
| Se usa | Con el hub encendido | Si el hub se apaga (respaldo `CLOUD_FALLBACK`) |

Este spec arregla las dos: que el token no se pierda y que la llave de nube llegue.

## 3. Decisiones

| # | Decisión | Por qué |
|---|----------|---------|
| D1 | **El hub Windows hace lo mismo que `hub-android`**: su propia llave al iniciar sesión, y la de cada báscula al aprobarla. | Ya está diseñado, probado y en producción del lado de `hub-android`, backend y báscula. La regla del ecosistema: un cambio de protocolo se hace en los dos hubs. |
| D2 | **Cualquiera de los dos roles** del hub (cajero o admin-sucursal) consigue las llaves. | El backend ya lo permite para llaves **de equipo**: acotadas a una tablet y revocables con ella. Un hub con sólo cajeros tiene que poder trabajar. |
| D3 | La llave de una báscula se pide **al aprobar**, no al solicitar. | Si no, cualquiera en la red podría hacer que se emitan llaves. |
| D4 | La llave de la báscula se entrega **una vez**, con el token, y **se borra del hub** al entregarla. | Igual que el token: el `raw_key` no debe quedar escrito más de lo necesario. |
| D5 | Que falle la llave **no rompe el emparejamiento**: la báscula queda emparejada con su token y sin respaldo, y el hub lo dice. | Emparejar es lo importante; la llave es el respaldo. |
| D6 | **La báscula guarda el token en cuanto lo recibe**, antes de validar. | Se entrega una sola vez. |
| D7 | «Hub sin catálogo» **no es un fallo de emparejamiento**; la báscula muestra **el mensaje del hub**. | Es transitorio, y el hub ya decía qué pasaba. |
| D8 | «Desemparejar este hub» **sólo si hay un hub emparejado**. | Un botón que no aplica confunde. |
| D9 | **Revocar una báscula en el hub revoca también su llave de nube.** Sin red, la revocación en la nube queda pendiente y se reintenta. | Revocar es retirar la confianza (tablet perdida, robada, de alguien que se fue). Con la llave viva, esa tablet seguiría vendiendo en la nube. Una sola acción, y sin llaves huérfanas. |
| D10 | **Desemparejar desde la báscula no toca su llave.** | Es cambiar de modo (dejar el hub y seguir en la nube), no retirar la confianza. Es el terreno de `feat/desemparejar-de-verdad`; aquí sólo se deja escrito para que no choque. |

## 4. Hub Windows

### 4.1 Su propia llave (port de `LlaveDeLaSucursal`)

- Tras un **login correcto** (y al restaurar sesión al arrancar), si `config.apiKey` está vacío: `POST /api/v1/hub/devices/{hubId}/api-key` con `{ name: hubName }` usando la sesión (API del hub, Bearer).
  - `201` con `raw_key` → `config.set('apiKey', raw_key)` y `refresher.refresh()` en el momento (el catálogo llega en segundos).
  - `403` → no reintenta (nube vieja); `401`/red/otro → reintenta en el siguiente login o arranque, no en bucle.
  - Si ya hay llave, **no** se pide (rotarla dejaría fuera a quien la esté usando).
- `hubId` es el identificador que el hub ya anuncia (`hub.id`, el mismo de `/branches/me` y mDNS). Pieza nueva: `src/main/sync/ownApiKey.js` (lógica pura con el cliente inyectado, testeable), cableada en el flujo de login de `index.js`/`ipc.js`.
- Configuración (admin) sigue permitiendo pegar una llave a mano; si alguien la pega, manda.

### 4.2 La llave de cada báscula (port de `LlaveDeLaBascula` + `ServidorLocal`)

- Columna nueva **`api_key`** (nullable) en la tabla local de dispositivos del hub (migración de SQLite como las existentes).
- **Al aprobar** una báscula (`hub:devices:approve`), tras marcarla aprobada: `POST /api/v1/hub/devices/{deviceId}/api-key` con `{ name: <nombre de la báscula> }` usando la sesión.
  - `raw_key` → se guarda en `api_key` de esa fila.
  - Falla → la aprobación **sigue valiendo**; el IPC devuelve el motivo (`«Hace falta una sesión abierta en el hub para darle llave propia.»`, `«No se pudo hablar con la nube.»`, `«La nube no devolvió ninguna llave.»`) y Básculas lo muestra en la fila («Sin respaldo en la nube: …») con **Reintentar** (vuelve a pedirla mientras el token no se haya entregado; si ya se entregó, reintentar exige re-emparejar y se dice así).
- **Al entregar el token** (`GET /api/v1/pairing/{deviceId}`, rama `approved && !token_delivered_at`): la respuesta añade **`api_key`** y **`cloud_url`** (= `config.backendUrl`) **sólo si hay llave**; después se marca entregado y se **borra** `api_key` de la fila. Campos **aditivos**: una báscula vieja los ignora.
- **Revocar** una báscula en el hub revoca también su llave de nube (D9, §4.4).

### 4.3 Mientras no haya catálogo

- Franja en **Inicio** y **Básculas** mientras `catalog.get('branch')` esté vacío: «Este hub todavía no tiene el catálogo de la sucursal: las básculas no pueden vender. Necesita internet y una sesión abierta.» con **Reintentar** (pide la llave propia si falta y refresca el catálogo).
- Los dos `503` de `localApiServer.js` añaden **`code: 'no_catalog'`** al cuerpo (aditivo; el `message` se conserva).

### 4.4 Revocar la báscula revoca su llave de nube (D9)

**Backend (aditivo).** Ruta nueva en el grupo del hub (`auth:sanctum` + `hub.role`), junto a la de crear:

| Método | Ruta | Nombre | Roles |
|---|---|---|---|
| DELETE | `devices/{deviceId}/api-key` | `api.hub.devices.api-key.revoke` | cajero y admin-sucursal |

- Borra (mismo criterio que `deviceApiKey`, que ya hace `->delete()` al rotar) las `ApiKey` con ese `device_id` **en la sucursal del usuario** (`branch_id` del token). Nunca toca llaves sin `device_id` (las sueltas de admin) ni de otra sucursal o empresa.
- **Idempotente:** si no había llave, `200` con `{ "revoked": 0 }`; si había, `{ "revoked": N }`. Así el reintento sin red es seguro.
- Misma justificación que la de crear: acotada a un equipo concreto, por eso la puede pedir un cajero (revocar llaves sueltas sigue siendo de admin).
- Docs: `docs/api/hub.md`, fila junto a `POST devices/{deviceId}/api-key`.

**Hub Windows.**
- `hub:devices:revoke` (tras revocar localmente, como hoy): borra la `api_key` de la fila si aún estaba (token no entregado) y llama a `DELETE /api/v1/hub/devices/{deviceId}/api-key` con la sesión.
  - `2xx` → listo.
  - Sin red, `5xx` o sin sesión → marca la fila con **`cloud_key_revoke_pending = 1`** (columna nueva, junto a `api_key`) y **no** bloquea la revocación local.
- **Reintento:** al volver la conexión (`onOnline`) y al iniciar sesión, recorre las filas con `cloud_key_revoke_pending = 1` y repite el `DELETE`; con `2xx` limpia la marca. `401/403` persistentes se dejan marcados y se reintentan en el siguiente login (otra sesión puede tenerlos).
- **UI:** el diálogo de revocar dice «La báscula dejará de poder conectarse a este hub **y a la nube**.» La fila revocada con la marca muestra «Llave de nube: pendiente de revocar (sin conexión)».
- **Re-emparejar** la misma tablet después funciona igual: al aprobar se pide una llave nueva (§4.2) y la marca pendiente de esa fila, si existía, se resuelve antes de pedirla (primero el `DELETE`, luego el `POST`), para no revocar la nueva por un reintento atrasado.

## 5. Báscula Android

### 5.1 El token no se pierde

`SetupViewModel.saveHubAndEnter`, al recibir `PairingOutcome.Approved`:

1. **Guardar primero** el hub (`configStore.saveHub(...)`), el nombre del equipo y la llave de nube si vino (esto último ya existe).
2. Después validar (`ApiHelper.validateConnection`):
   - `ok` → como hoy.
   - `NO_CATALOG` → **queda emparejada**: «Emparejada con {hub}. El hub todavía no tiene el catálogo: pide que inicien sesión en el hub.» con **Reintentar** y reintento cada 15 s mientras la pantalla esté abierta; al conseguirlo entra.
   - `UNAUTHORIZED` (401) → el token no sirve: `forgetHub()` y fallo, como hoy.
   - otro (red, 5xx sin `code`) → queda emparejada con el mensaje y Reintentar.

### 5.2 El mensaje del hub

`Api.kt` (`validateConnection` y el helper de la línea ~232): ante un `HttpException`, leer `message` y `code` del cuerpo JSON; si hay `message`, mostrarlo; `code == 'no_catalog'` → `ConnectionFailure.NO_CATALOG`. Sin cuerpo legible, el genérico.

### 5.3 Hub guardado pero sin catálogo

`HubStatus` gana **`NO_CATALOG`** (la sonda de `ConnectionViewModel`/`HubLocator` lo distingue de `UNREACHABLE` por el `code`). En Conexión: «El hub todavía no tiene el catálogo · pide que inicien sesión en el hub», tarjeta no seleccionable (como `REVOKED`). `ServerSelector` no cambia: no es sano, así que si hay llave de nube y respaldo activo, la báscula vende por la nube mientras tanto.

### 5.4 Desemparejar sólo si hay hub

`AdvancedSettingsScreen`: la tarjeta «Hub» muestra «Desemparejar este hub» sólo si hay un hub guardado (`state.hubPaired`, leído del `ConfigStore` al entrar). Si no, «Sin hub emparejado» y ninguna acción (buscar ya está en `HubDiscoveryCard`).

## 6. Qué no hace

- No toca el backend ni `hub-android`.
- No revoca la llave de nube al **desemparejar desde la báscula** (D10).
- `hub-android` no adopta la revocación de §4.4 en este spec: la ruta del backend le sirve igual y queda anotado como siguiente paso.
- No cambia `protocol_version`: sólo campos aditivos (`api_key`, `cloud_url` en el acuse — los que `hub-android` ya manda —, y `code` en un 503).

## 7. Compatibilidad y conformidad

- La suite de conformidad (`carniceria-hub/scripts/conformidad.mjs`) corre los mismos casos contra los dos hubs: si tiene (o se añade) un caso de «el acuse trae `api_key`/`cloud_url` cuando hay llave», el hub Windows debe pasarlo. El plan lo revisa.
- La rama `feat/desemparejar-de-verdad` del hub (trabajo en curso de otra sesión) toca `localApiServer.js`: el plan parte de `origin/main` y resuelve el choque al fusionar, sin tocar esa rama.

## 8. Pruebas

**Hub (Vitest)**
- Llave propia: sin llave + login → pide `devices/{hubId}/api-key`, guarda y refresca; con llave → no pide; 403 → no reintenta; fallo → se reintenta en el siguiente login.
- Aprobar una báscula pide su llave y la guarda; si falla, la aprobación queda y devuelve el motivo.
- Entregar el token incluye `api_key` + `cloud_url` sólo si hay llave, una sola vez, y borra la llave de la fila.
- Los 503 llevan `code: 'no_catalog'`.
- Revocar llama al `DELETE` con el `deviceId`; sin red deja `cloud_key_revoke_pending = 1` y la revocación local ocurre igual; al volver la red se reintenta y limpia la marca; re-emparejar con marca pendiente hace primero el `DELETE` y después el `POST`.

**Backend (PHPUnit)**
- `DELETE devices/{deviceId}/api-key` con cajero y con admin-sucursal borra sólo las llaves de ese equipo en su sucursal; no toca llaves sueltas, de otro equipo, de otra sucursal ni de otra empresa.
- Idempotente: segunda llamada → `revoked: 0`, 200.
- Sin token → 401; admin-empresa → 403 (`hub.role`).
- Tras revocar, esa llave recibe 401 en la Scale API.

**Báscula Android (JUnit)**
- Aprobado + `no_catalog`: token guardado, estado «emparejada, esperando catálogo»; aprobado + 401: token borrado.
- El `message` del cuerpo llega al texto; sin cuerpo, el genérico.
- 503 `no_catalog` en la sonda → `HubStatus.NO_CATALOG`.
- `hubPaired = false` oculta «Desemparejar este hub».

**A mano (Surface/tablet reales)**
1. Hub reinstalado, entrar **como cajero** → Configuración/Dispositivo muestra que ya tiene llave y catálogo; sin copiar nada.
2. Emparejar una tablet recién instalada → entra a vender; en su Conexión aparece la nube como respaldo disponible.
3. Apagar el hub → la tablet sigue vendiendo por la nube (respaldo).
5. Revocar la tablet en el hub → ya no vende ni por el hub ni por la nube; en la web su llave desapareció. Repetir sin internet → queda «pendiente de revocar» y se resuelve al volver la red.
4. Tablet sin hub → Avanzada no ofrece desemparejar.

## 9. Documentación al implementar

`carniceria-saas/docs/api/hub.md` (la ruta `DELETE`), `carniceria-hub/docs/api-local.md` (acuse con `api_key`/`cloud_url`, `code` del 503), la doc del hub sobre catálogo/sincronización y `releases.md`, `bascula-android/README.md` (emparejamiento), `carniceria-saas/docs/arquitectura/ecosistema.md` (el hub Windows alcanza a `hub-android`), y este spec → Implementado.
