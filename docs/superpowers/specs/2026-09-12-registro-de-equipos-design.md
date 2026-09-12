# Registro de equipos — nube y web (entrega 1 de 4)

**Fecha:** 2026-09-12
**Estado:** Aprobado en brainstorming, pendiente de plan
**Repo:** `carniceria-saas` (Laravel 13 + Vue 3 + Inertia)
**Entregas siguientes:** 2 · báscula Surface (`bascula`), 3 · báscula Android (`bascula-android`), 4 · hubs (`carniceria-hub` y `hub-android`). Cada una con su spec y su PR.

## Contexto

Hoy la nube no sabe qué equipos existen en una sucursal: solo tiene la API key,
que identifica a la sucursal, y el `origin_name` que cada báscula escribe en sus
ventas. No hay forma de ver desde la web qué básculas hay, si están encendidas, qué
versión tienen ni cuánta batería les queda. El 2026-09-12 el usuario tuvo que
preguntar a cada Surface qué versión llevaba, y pidió dos cosas: **control de
equipos desde la web** y **aviso cuando una batería baje del 20 %**.

### Decisiones tomadas en brainstorming (2026-09-12)

- **Transporte: un endpoint nuevo y aditivo en la Scale API**,
  `POST /api/v1/devices/heartbeat`, con la misma `X-Api-Key`. Los endpoints que
  usan las básculas viejas no cambian ni un byte; ellas simplemente nunca llaman
  al nuevo. Los hubs usan su equivalente en la superficie Sanctum.
- **Identidad por identificador del aparato**, no por nombre ni por API key: cada
  app genera uno una sola vez y lo conserva entre actualizaciones (Android ya lo
  hace para emparejarse con el hub). El nombre es un dato más, editable desde la
  web.
- **Datos del panel:** identidad y tipo, versión y sistema, batería y energía,
  actividad y red. Todo.
- **Avisos:** batería baja (20 %, otra vez al 10 %), equipo sin reportar, versión
  atrasada y equipo nuevo. Llegan a **admin de sucursal y admin de empresa**; no al
  cajero.
- **Cadencia:** latido cada 5 minutos con la app abierta, más uno inmediato al
  arrancar, al cruzar 20 % / 10 % y al enchufar o desenchufar.
- **Panel:** sección **Equipos** en Sucursal, junto a API keys; el admin de
  empresa ve la misma pantalla con todas sus sucursales. **Tarjetas por equipo**
  (mockup aprobado), avisos activos arriba, básculas viejas "sin registro" abajo.
- **Acciones del administrador:** renombrar, silenciar avisos, dar de baja.
- Las básculas viejas (0.2.0 Windows, Android sin actualizar) **siguen vendiendo
  igual** y aparecen como "sin registro" deducidas de sus ventas.

## Objetivos

1. Que cada equipo pueda presentarse y reportar su estado a la nube con una sola
   llamada nueva, sin tocar nada de lo que ya usan las básculas.
2. Un panel de equipos por sucursal (y global para la empresa) con estado, versión,
   batería y actividad, y acciones de administración.
3. Avisos en la campana para las cuatro situaciones decididas, sin repetirse.

## No objetivos

- **No cambia ningún endpoint existente** de `/api/v1/*`. `ScaleLegacyContractTest`
  debe seguir verde sin modificarse.
- No se implementa el lado cliente (Surface, Android, hubs): entregas 2–4. Esta
  entrega sale a producción con el panel mostrando solo "sin registro", y eso ya
  es útil.
- Sin acciones remotas sobre el equipo (reiniciar, forzar actualización).
- Sin histórico de batería ni gráficas; solo el último estado.
- Sin horario configurable por sucursal para "sin reportar": ventana fija 08:00–20:00
  en la zona horaria de la app (`America/Mexico_City`). Se hace configurable si
  hace falta.
- Sin notificaciones por correo ni push: solo la campana (`database` + `broadcast`).

## Diseño

### 1. Modelo `Device`

Tabla `devices`, modelo `App\Models\Device` con `BelongsToTenant` (hay que añadirlo a
la lista del `CLAUDE.md` raíz).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint | |
| `tenant_id` | fk | `BelongsToTenant` |
| `branch_id` | fk | sucursal de la API key (o del usuario del hub) |
| `device_id` | string(64) | identificador del aparato; **único por tenant** (`unique(tenant_id, device_id)`) |
| `kind` | string(32) | `scale_android` · `scale_windows` · `hub_windows` · `hub_android` |
| `name` | string(100) | el que manda el equipo; `display_name` lo pisa si existe |
| `display_name` | string(100) nullable | nombre puesto desde la web (renombrar) |
| `app_version` | string(32) nullable | |
| `os` | string(100) nullable | p. ej. `Windows 11 23H2`, `Android 14` |
| `model` | string(100) nullable | p. ej. `Surface Go 3`, `SM-X230` |
| `battery_level` | tinyint nullable | 0–100; `null` si el equipo no tiene batería o no la reporta |
| `battery_charging` | bool nullable | |
| `connection` | string(16) nullable | `cloud` · `hub`: a qué está vendiendo, lo declara el equipo |
| `local_ip` | string(45) nullable | |
| `via` | string(16) | `cloud` · `hub`: por dónde llegó el latido, lo decide el servidor según el endpoint. Divergen legítimamente: una Android emparejada que cayó en respaldo vende contra la nube (`connection = cloud`) pero puede seguir reportando por el hub (`via = hub`), y al revés. No son redundantes. |
| `last_seen_at` | timestamp | último latido |
| `first_seen_at` | timestamp | |
| `muted_at` | timestamp nullable | silenciado: no genera avisos |
| `retired_at` | timestamp nullable | dado de baja: no se muestra ni avisa; si vuelve a reportar, se reactiva |
| `battery_alert_level` | tinyint nullable | umbral ya avisado en el episodio actual (`20` o `10`); se limpia al cargar |
| `silent_alerted_at` | timestamp nullable | ya se avisó del silencio actual; se limpia al volver a reportar |
| `outdated_alert_version` | string(32) nullable | versión atrasada de la que ya se avisó |
| `timestamps` | | |

Índices: `(tenant_id, branch_id)`, `last_seen_at`.

Estados derivados (accessor `status`, no columna):

| Estado | Regla |
|---|---|
| `online` | `last_seen_at` hace menos de 10 min (dos latidos) |
| `battery_low` | `online` y `battery_level <= 20` y no cargando |
| `silent` | `last_seen_at` hace más de 30 min |
| `stale` | entre 10 y 30 min |
| `retired` | `retired_at` no nulo |

El estado del panel es la realidad en todo momento y **no depende de la ventana
horaria**: un equipo apagado a medianoche se ve `silent` (rojo) y aparece en la
franja de avisos activos del panel aunque la campana no haya sonado. La ventana
solo decide cuándo se **notifica**; el panel siempre muestra lo que hay.

### 2. El latido

**`POST /api/v1/devices/heartbeat`** — grupo `auth.apikey` existente (mismo
throttle que el resto). Controlador `Api\DeviceHeartbeatController@store`.

Payload (todo salvo `device_id` y `kind` es opcional; lo que no viene no pisa lo
guardado):

```json
{
  "device_id": "f3a1…-uuid",
  "kind": "scale_windows",
  "name": "Mostrador Surface",
  "app_version": "0.4.1",
  "os": "Windows 11 23H2",
  "model": "Surface Go 3",
  "battery": { "level": 14, "charging": false },
  "connection": "cloud",
  "local_ip": "192.168.1.31"
}
```

Validación: `device_id` requerido, string ≤ 64, `[A-Za-z0-9._-]+`; `kind` requerido,
en la lista; `name` ≤ 100; `battery.level` entero 0–100; `battery.charging` bool;
`battery` puede ser `null` explícito (equipo sin batería → limpia ambos campos);
`connection` en `cloud|hub`; `local_ip` IP válida.

Comportamiento (`DeviceHeartbeatService::record(Branch, array, via)`), en una
transacción:

1. `updateOrCreate` por `(tenant_id, device_id)`. Si ya existía en **otra
   sucursal** del mismo tenant (se movió la tablet), se actualiza `branch_id`.
2. Si estaba `retired_at`, se limpia (volvió).
3. `last_seen_at = now()`; `first_seen_at` solo al crear; `silent_alerted_at = null`.
4. Devuelve `201` al crear y `200` al actualizar, con `{ "data": { "device_id",
   "name", "display_name", "status", "server_time" } }`.
5. Después de guardar, llama a `DeviceAlertService::afterHeartbeat($device, $wasNew)`.

Idempotente por naturaleza: dos latidos iguales seguidos dejan el mismo estado.

**`POST /api/v1/hub/devices/heartbeat`** — grupo `auth:sanctum` + `hub.role`.
Mismo payload; `branch_id` sale del usuario; `via = 'hub'`. Sirve para que el hub
se reporte a sí mismo (`kind = hub_windows|hub_android`) y para que reenvíe el
latido de cada báscula emparejada que no tenga credenciales de nube. Mismo
servicio, mismo resultado.

### 3. Versión publicada por tipo

Tabla `device_releases`: `kind` (pk), `version`, `known_since` (desde cuándo se
conoce **esta** versión: solo cambia cuando cambia `version`), `checked_at`
(última consulta al feed, informativa). Comando `devices:sync-releases` (cada hora
en `bootstrap/app.php`) lee los feeds públicos del bucket, los mismos que consumen
los actualizadores:

| `kind` | Feed |
|---|---|
| `scale_windows` | `…/bascula/win/latest.yml` → clave `version` |
| `scale_android` | `…/android/latest.json` → clave `versionName` |
| `hub_windows` | `…/hub/win/latest.yml` → clave `version` |
| `hub_android` | sin feed todavía: se omite y el panel no marca atraso |

La URL base vive en `config/devices.php` (`releases_base_url`). Un feed que no
responde no borra el valor anterior; se registra en el log y se sigue. Comparación
con `version_compare`. **`known_since` no se toca si la versión leída es la misma
que la guardada**; si se reescribiera en cada corrida, "lleva más de 24 h
publicada" nunca sería cierto y el aviso de versión atrasada quedaría mudo.

### 4. Avisos

Cuatro `Notification` con `via() === ['database', 'broadcast']` y `level =
'important'`, en `App\Notifications\Device*`:

| Notificación | `type` | Cuándo | Cuerpo |
|---|---|---|---|
| `DeviceBatteryLow` | `device.battery.low` | Latido sin cargar: si `level <= 10` y `battery_alert_level != 10` → un aviso y marca `10` (aunque venga directo de `null`: un solo aviso, no dos). Si `10 < level <= 20` y `battery_alert_level` nulo → un aviso y marca `20`. Latido cargando o `level > 20` → limpia la marca. | "Balanza 2 está al 14 % y no está cargando." |
| `DeviceRegistered` | `device.registered` | Primer latido de un `device_id` desconocido en el tenant. | "Un equipo nuevo reporta en Centro: Balanza 3 (Android, 1.6.2)." |
| `DeviceSilent` | `device.silent` | `devices:check`, solo entre 08:00 y 20:00 hora local: el silencio se mide **dentro de la ventana**: `silencio = now - max(last_seen_at, inicio de la ventana de hoy)`. Si `silencio > 30 min` y `silent_alerted_at` nulo → un aviso y marca `silent_alerted_at`. La ventana aplica **los siete días** (las carnicerías abren fines de semana); no hay noción de día hábil. Así una tablet apagada de noche no avisa a las 08:00; avisa en el primer chequeo pasados 30 min de ventana (hacia las 08:35, el cron es de 5 min) si nadie la encendió, y una sola vez por episodio: solo un latido limpia la marca. Un equipo apagado desde el viernes avisa el sábado por la mañana y no vuelve a avisar hasta que reporte y se calle otra vez. | "Mostrador Surface no reporta desde las 09:12." |
| `DeviceOutdated` | `device.outdated` | `devices:check`: hay `device_releases` para su `kind`, `app_version` menor, `known_since` hace más de 24 h, y `outdated_alert_version != version`. Marca la versión. | "Balanza 2 sigue en 1.6.1; la 1.6.2 lleva un día publicada." |

Destinatarios (`DeviceAlertService::recipients(Device)`): usuarios del tenant con
rol `admin-sucursal` y `branch_id` del equipo, más los `admin-empresa` del tenant.
Misma forma que `SaleCancellationNotifier::branchAdmins`, y el mismo `guard`: un
fallo al notificar se registra y no tumba el latido.

Equipos con `muted_at` o `retired_at` no generan ningún aviso, pero las marcas
(`battery_alert_level`, `silent_alerted_at`, `outdated_alert_version`) **se siguen
actualizando igual**: solo se suprime el envío. Así, al reactivar un equipo
silenciado no se dispara de golpe todo lo que pasó mientras estaba callado. El payload de cada
aviso lleva `device_id`, `branch_id`, `name` y `level`, para que la campana pueda
enlazar al panel.

`devices:check` corre `everyFiveMinutes()` en `bootstrap/app.php`.

### 5. Panel

**Sucursal → Equipos.** Ruta `GET /{tenant}/sucursal/equipos` (`sucursal.devices.index`),
`Sucursal\DeviceController@index`, página `Sucursal/Equipos/Index.vue` con
`SucursalLayout`. Enlace en el menú de Sucursal junto a API keys.

Props: `devices` (activos de la sucursal, con `status`, `display_name ?? name`,
`outdated` bool, `release_version`), `alerts` (equipos con `battery_low`, `silent`
u `outdated`, para la franja de arriba), `unregistered` (ver abajo), `tenant`.

Tarjetas como el mockup: nombre y tipo, chip de estado con color (verde `online`,
ámbar `battery_low`/`stale`, rojo `silent`), versión con chip "al día" / "atrasada",
batería con barra y "cargando / sin cargar / enchufado", "reportó hace N", IP y
un chip **"vende contra el hub / la nube"** que muestra `connection` (lo que el
equipo declara), última venta. `via`, `os` y `model` **no van en la tarjeta**: se
ven en el panel lateral de detalle, donde `via` se muestra como "reporta por el
hub / por la nube" junto a `connection`, para que el caso divergente (vende contra
la nube pero reporta por el hub) se lea completo. Tocar la tarjeta abre ese panel
lateral con el detalle y las tres acciones:

| Acción | Ruta | Efecto |
|---|---|---|
| Renombrar | `PATCH /{tenant}/sucursal/equipos/{device}` `{display_name}` | ≤ 100, vacío = quitar el alias |
| Silenciar / reactivar avisos | `PATCH …/{device}/silencio` | alterna `muted_at` |
| Dar de baja | `DELETE …/{device}` | pone `retired_at`; desaparece del panel; si vuelve a reportar, reaparece |

Todo con `BelongsToTenant` y comprobando `branch_id` del usuario (404 si no es de
su sucursal). Las rutas caen en los grupos `role:admin-sucursal|superadmin` y
`role:admin-empresa|superadmin` existentes, así que `superadmin` ve el panel: es
lo esperado, igual que el resto de secciones.

**"Última venta" y "sin registro".** `last_sale_at` por equipo =
`max(sales.created_at)` con `sales.origin_name = device.name` y misma sucursal en
los últimos 30 días (accountable). "Sin registro" = los `origin_name` distintos de
ventas de la sucursal en 30 días que no coinciden con el `name` de ningún equipo
registrado; se listan abajo con su última venta. Es una deducción, no una
identidad: si dos básculas viejas se llaman igual, se ven como una.

**Empresa → Equipos.** Ruta `GET /{tenant}/empresa/equipos` (`empresa.devices.index`),
`Empresa\DeviceController@index`, página `Empresa/Equipos/Index.vue` con
`EmpresaLayout`: la misma tarjeta, agrupada por sucursal, con las mismas tres
acciones (rutas `empresa.devices.*`, sin restricción de sucursal). Enlace en el
menú de Empresa.

### 6. Estados y errores

| Situación | Qué pasa |
|---|---|
| Latido sin `device_id` o `kind` inválido | `422` con los mensajes de validación; nada se guarda |
| Latido de un equipo dado de baja | Se reactiva y se procesa normal |
| Mismo `device_id` desde otra sucursal del tenant | Se mueve de sucursal; no se crea otro |
| Mismo `device_id` en otro tenant | Es otro equipo (la unicidad es por tenant) |
| Fallo al notificar | Se registra en el log; el latido responde bien |
| Feed de versiones caído | Se conserva la versión anterior; se registra |
| `battery: null` | Limpia nivel y carga; no avisa |
| Equipo silenciado con batería al 5 % | Se ve en el panel; no llega aviso |

### 7. Pruebas

Feature (`tests/Feature/Api/DeviceHeartbeatTest.php`, `tests/Feature/Hub/…`,
`tests/Feature/Sucursal/DevicesTest.php`, `tests/Feature/Console/DevicesCheckTest.php`):

- El latido crea con `201` y actualiza con `200`; no duplica; respeta el tenant
  (mismo `device_id` en dos tenants = dos filas); mueve de sucursal; reactiva un
  dado de baja; valida el payload; `battery: null` limpia.
- **`ScaleLegacyContractTest` sigue verde sin tocarlo.**
- Batería: 25 % → nada; 14 % → un aviso; 14 % otra vez → nada; 8 % → segundo
  aviso; cargando → limpia; 14 % de nuevo → avisa otra vez. Primer latido directo
  al 6 % → **un solo** aviso (marca `10`), y 6 % otra vez → nada. Silenciado → nada.
- Equipo nuevo → aviso a admin de sucursal y de empresa, no al cajero ni a otra
  sucursal.
- `devices:check`: silencio > 30 min dentro del horario → un aviso, no dos; fuera
  del horario → ninguno; versión atrasada con release de más de 24 h → un aviso
  por versión.
- `devices:sync-releases` con feeds falsos (`Http::fake`): parsea `latest.yml` y
  `latest.json`; un feed roto no borra lo anterior; **la misma versión en dos
  corridas seguidas no toca `known_since`** (solo `checked_at`); una versión nueva
  sí lo reinicia.
- `devices:check` un sábado a las 08:35 con un equipo callado desde el viernes →
  un aviso; el domingo → ninguno (sigue marcado); tras un latido y 31 min de
  silencio en ventana → otro.
- Panel: el admin de sucursal solo ve los suyos; renombrar/silenciar/baja; 404 en
  equipo de otra sucursal; "sin registro" aparece a partir de las ventas.

Pint antes del PR. La verificación visual del panel es manual.

### 8. Documentación

- Nuevo `docs/modulos/equipos.md` (registro, latido, avisos, panel) y entrada en
  `docs/README.md`.
- `docs/api/endpoints.md`: sección nueva "Latido de equipo" marcada como aditiva.
- `docs/api/hub.md`: el endpoint de latido del hub (113 endpoints).
- `docs/modulos/avisos.md`: las cuatro notificaciones nuevas en la tabla.
- `CLAUDE.md` raíz: `Device` en la lista de `BelongsToTenant` (29 modelos).
- `docs/arquitectura/ecosistema.md`: el latido como capacidad nueva de la Scale API
  y de la superficie del hub, con la regla de que es aditivo.

## Entregas siguientes (contrato que deben cumplir)

- **Surface (`bascula`):** `device_id` generado una vez y guardado en la
  configuración; latido al arrancar, cada 5 min, al cruzar 20 % / 10 % y al cambiar
  la carga; batería con `navigator.getBattery()` en el renderer (`null` si no
  existe); `kind = scale_windows`; `os`/`model` desde `process` en el main.
- **Android (`bascula-android`):** reutiliza el `device_id` del emparejamiento;
  `BatteryManager`; si solo tiene credenciales del hub, manda el latido al hub por
  LAN (`POST /api/v1/devices/heartbeat` local) y el hub lo reenvía.
- **Hubs:** se reportan a sí mismos por `/api/v1/hub/devices/heartbeat`; aceptan el
  latido local de sus básculas y lo reenvían con `via = 'hub'`. Endpoint local
  nuevo declarado en `cap`, **sin subir `protocol_version`**.
