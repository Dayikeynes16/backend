# Equipos (registro de básculas y hubs)

Qué equipos hay en cada sucursal, si están encendidos, qué versión tienen y cuánta batería les queda. Y avisos cuando algo de eso va mal.

**Estado:** nube y web implementadas (2026-09-16). Los clientes (Surface, Android, hubs) todavía no mandan el latido: hasta que lo hagan, el panel solo muestra la lista «Sin registro».

## Por qué existe

La nube solo conocía la API key (la sucursal) y el `origin_name` de cada venta. Para saber qué versión tenía cada Surface había que preguntar equipo por equipo. El 2026-09-12 se pidió control desde la web y aviso al bajar del 20 % de batería, sin romper las básculas viejas que no se auto-actualizan.

## Cómo funciona

1. Cada equipo manda un **latido** `POST /api/v1/devices/heartbeat` (misma `X-Api-Key`, ver [endpoints.md](../api/endpoints.md#post-apiv1devicesheartbeat)) o, los hubs, `POST /api/v1/hub/devices/heartbeat` (Sanctum, ver [hub.md](../api/hub.md#equipos-latido)). Es un endpoint **nuevo y aditivo**: las básculas viejas no lo llaman y nada de lo que usan cambia (`tests/Feature/Api/ScaleLegacyContractTest.php` sigue intacto).
2. La identidad es `(tenant_id, device_id)`; el `device_id` lo genera la app una vez (`[A-Za-z0-9._-]{1,64}`). El nombre es un dato más; desde la web se le pone un alias (`display_name`), que gana al nombre del equipo en todo lo que se muestra.
3. `DeviceHeartbeatService` registra o actualiza: lo que no viene no pisa lo guardado; `battery: null` limpia nivel y carga; un equipo dado de baja que vuelve a reportar se reactiva; si reporta desde otra sucursal del mismo tenant, se muda (no se duplica). Ante la carrera de dos primeros latidos simultáneos, reintenta.
4. `DeviceAlertService` decide avisos con marcas en la propia fila (`battery_alert_level`, `silent_alerted_at`, `outdated_alert_version`) para no repetir. Silenciado o de baja: las marcas se actualizan igual, el envío se suprime. Un fallo al notificar se registra en el log y el latido responde bien.
5. Dos comandos programados: `devices:check` (cada 5 min) revisa silencio y versión atrasada; `devices:sync-releases` (cada hora) lee los feeds de publicación de cada app (`config/devices.php`, los mismos que consumen los actualizadores). `hub_android` no tiene feed todavía, así que nunca se marca atrasado.

## Modelo

`devices` (`BelongsToTenant`): `device_id`, `kind` (`scale_android` · `scale_windows` · `hub_windows` · `hub_android`), `name`, `display_name`, `app_version`, `os`, `model`, `battery_level`, `battery_charging`, `connection` (`cloud` · `hub`: contra qué vende), `via` (`cloud` · `hub`: por dónde llegó el latido), `local_ip`, `first_seen_at`, `last_seen_at`, `muted_at`, `retired_at` y las tres marcas de avisos. Único por `(tenant_id, device_id)`.

`device_releases`: última versión publicada por `kind` (`version`, `known_since`, `checked_at`). `known_since` solo cambia cuando cambia la versión: de ahí sale el margen de 24 h antes de avisar.

## Estados

Derivados en el accessor `status` del modelo, nunca guardados:

| Estado | Regla |
|---|---|
| `online` | reportó hace < 10 min |
| `battery_low` | online, ≤ 20 % y sin cargar |
| `stale` | 10–30 min sin reportar |
| `silent` | > 30 min sin reportar |
| `retired` | dado de baja (gana a todo) |

El panel muestra la realidad siempre. La ventana 08:00–20:00 (`America/Mexico_City`, los siete días) solo decide cuándo se **notifica** el silencio: un equipo apagado de noche no despierta a nadie.

## Avisos

Van por el circuito de [avisos persistentes](avisos.md) (`database` + `broadcast`), nivel `important`, a los `admin-sucursal` de la sucursal del equipo y a los `admin-empresa` del tenant. Nunca al cajero.

| Aviso | `type` | Cuándo |
|---|---|---|
| Batería baja | `device.battery.low` | ≤ 20 % sin cargar; otra vez al ≤ 10 %; se rearma al cargar o subir de 20 % |
| Equipo nuevo | `device.registered` | primer latido de un `device_id` |
| Sin reportar | `device.silent` | > 30 min de silencio dentro de la ventana, una vez por episodio (cualquier latido lo cierra) |
| Versión atrasada | `device.outdated` | versión menor a la publicada hace > 24 h, una vez por versión publicada |

## Panel

- **Sucursal → Equipos** (`/{tenant}/sucursal/equipos`, `Sucursal\DeviceController`, `BranchDevicesQuery`): tarjetas por equipo con chip de estado, versión (con «al día» / «atrasada · hay X»), batería, último reporte, última venta, IP y contra qué vende. Arriba, la franja «Requieren atención» (batería baja, sin reportar, atrasados). Abajo, **«Sin registro»**: los `origin_name` de ventas contables de los últimos 30 días que no coinciden con el `name` de ningún equipo registrado. Es una deducción, no una identidad: dos básculas viejas con el mismo nombre se ven como una.
- **Empresa → Equipos** (`/{tenant}/empresa/equipos`, `Empresa\DeviceController`): el mismo tablero, agrupado por sucursal.
- Tocar una tarjeta abre el panel lateral con el detalle completo (`os`, `model`, «reporta por» / «vende contra») y las tres acciones:

| Acción | Ruta | Efecto |
|---|---|---|
| Renombrar | `PATCH …/equipos/{device}` `{display_name}` | ≤ 100 caracteres; vacío quita el alias |
| Silenciar / reactivar avisos | `PATCH …/equipos/{device}/silencio` | alterna `muted_at` |
| Dar de baja | `DELETE …/equipos/{device}` | pone `retired_at`; desaparece del panel; si vuelve a reportar, reaparece |

En Sucursal, un equipo de otra sucursal da 404; en Empresa no hay restricción de sucursal. El `superadmin` entra a ambos paneles, como al resto de secciones.

Componentes: `resources/js/Components/Devices/{DeviceCard,DeviceDetailPanel,DevicesBoard}.vue`.

## Entregas siguientes

Cada cliente manda el latido al arrancar, cada 5 min, al cruzar 20 % / 10 % y al enchufar o desenchufar. Surface (`bascula`) genera su `device_id` y lee `navigator.getBattery()`; Android reutiliza el `device_id` del emparejamiento y, sin nube, late al hub por LAN; los hubs (Electron y Android) se reportan y reenvían el de sus básculas con un endpoint local nuevo anunciado en `cap`, sin subir `protocol_version`. Contrato completo en el spec `docs/superpowers/specs/2026-09-12-registro-de-equipos-design.md` y plan en `docs/superpowers/plans/2026-09-12-registro-de-equipos.md`.

## Tests

`tests/Feature/Api/DeviceHeartbeatTest.php` (Scale API), `tests/Feature/Hub/DeviceHeartbeatTest.php` (hub), `tests/Feature/Devices/DeviceAlertsTest.php` (deduplicación de avisos), `tests/Feature/Console/DeviceCommandsTest.php` (comandos), `tests/Unit/DeviceStatusTest.php` (estados), `tests/Feature/Sucursal/DevicesTest.php` y `tests/Feature/Empresa/DevicesTest.php` (paneles). `ScaleLegacyContractTest` no se toca.
