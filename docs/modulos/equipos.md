# Equipos (registro de básculas y hubs)

Qué equipos hay en cada sucursal, si están encendidos, qué versión tienen y cuánta batería les queda. Y avisos cuando algo de eso va mal.

**Estado:** nube y web implementadas. Las tres apps publicadas el 2026-09-17 —Surface 0.4.2, Android 1.6.3 y hub Electron 1.2.1— ya mandan el latido; el hub Android tiene el código mergeado pero todavía no tiene release. Lo que falta es verlo funcionar en hardware real: nadie ha visto todavía una Surface de verdad reportando su porcentaje de batería.

## Por qué existe

La nube solo conocía la API key (la sucursal) y el `origin_name` de cada venta. Para saber qué versión tenía cada Surface había que preguntar equipo por equipo. El 2026-09-12 se pidió control desde la web y aviso de batería baja, sin romper las básculas viejas que no se auto-actualizan. El umbral nació fijo en 20 % / 10 %; desde el 2026-09-19 cada sucursal lo decide (ver «Estados»), con esos mismos números de fábrica.

## Cómo funciona

1. Cada equipo manda un **latido** `POST /api/v1/devices/heartbeat` (misma `X-Api-Key`, ver [endpoints.md](../api/endpoints.md#post-apiv1devicesheartbeat)) o, los hubs, `POST /api/v1/hub/devices/heartbeat` (Sanctum, ver [hub.md](../api/hub.md#equipos-latido)). Es un endpoint **nuevo y aditivo**: las básculas viejas no lo llaman y nada de lo que usan cambia (`tests/Feature/Api/ScaleLegacyContractTest.php` sigue intacto).
2. La identidad es `(tenant_id, device_id)`; el `device_id` lo genera la app una vez (`[A-Za-z0-9._-]{1,64}`). El nombre es un dato más; desde la web se le pone un alias (`display_name`), que gana al nombre del equipo en todo lo que se muestra.
3. `DeviceHeartbeatService` registra o actualiza: lo que no viene no pisa lo guardado; `battery: null` limpia nivel y carga; un equipo dado de baja que vuelve a reportar se reactiva; si reporta desde otra sucursal del mismo tenant, se muda (no se duplica). Ante la carrera de dos primeros latidos simultáneos, reintenta.
4. `DeviceAlertService` decide avisos con marcas en la propia fila (`battery_alert_level`, `silent_alerted_at`, `outdated_alert_version`) para no repetir. Silenciado o de baja: las marcas se actualizan igual, el envío se suprime. Un fallo al notificar se registra en el log y el latido responde bien.
5. Dos comandos programados: `devices:check` (cada 5 min) revisa silencio y versión atrasada; `devices:sync-releases` (cada hora) lee los feeds de publicación de cada app (`config/devices.php`, los mismos que consumen los actualizadores). `hub_android` no tiene feed todavía, así que nunca se marca atrasado.

## Modelo

`devices` (`BelongsToTenant`): `device_id`, `kind` (`scale_android` · `scale_windows` · `hub_windows` · `hub_android`), `name`, `display_name`, `app_version`, `os`, `model`, `battery_level`, `battery_charging`, `connection` (`cloud` · `hub`: contra qué vende), `via` (`cloud` · `hub`: por dónde llegó el latido; la superficie del hub lo fija en `hub` y la Scale API acepta que un hub sin sesión lo declare), `local_ip`, `first_seen_at`, `last_seen_at`, `muted_at`, `retired_at` y las tres marcas de avisos. Único por `(tenant_id, device_id)`. `battery_alert_level` guarda `warn` / `critical` (no el número: si guardara el umbral, moverlo a media tarde dejaría la marca sin nada con qué compararse).

`branches`: `battery_warn_threshold` y `battery_critical_threshold` (`smallint`, 20 y 10 de fábrica) — los dos umbrales de la sucursal, ver «Los dos umbrales» más abajo.

`device_releases`: última versión publicada por `kind` (`version`, `known_since`, `checked_at`). `known_since` solo cambia cuando cambia la versión: de ahí sale el margen de 24 h antes de avisar.

## Estados

Derivados en el accessor `status` del modelo, nunca guardados:

| Estado | Regla |
|---|---|
| `online` | reportó hace < 10 min |
| `battery_low` | online, ≤ el umbral de aviso de la sucursal (`battery_warn_threshold`, 20 % de fábrica) y sin cargar |
| `battery_critical` | online, ≤ el umbral urgente de la sucursal (`battery_critical_threshold`, 10 % de fábrica) y sin cargar |
| `stale` | 10–30 min sin reportar |
| `silent` | > 30 min sin reportar |
| `retired` | dado de baja (gana a todo) |

El orden de precedencia completo es `retired → silent → stale → battery_critical → battery_low → online`: las dos alertas de batería exigen que el equipo esté en línea, así que uno que lleva veinte minutos callado con una última lectura del 5 % se sigue viendo `silent`, no en alerta de batería — una lectura vieja no dice nada del presente.

Los dos umbrales los decide cada sucursal (`app/Support/BatteryThresholdRules.php`): de 5 en 5, aviso entre 10 % y 95 %, urgente entre 5 % y 90 %, y el urgente siempre menor que el aviso. `BatteryThresholds::fromBranch()` es el único sitio que compara un nivel contra ellos; sin sucursal o sin valores guardados cae a 20/10.

El panel muestra la realidad siempre. La ventana 08:00–20:00 (`America/Mexico_City`, los siete días) solo decide cuándo se **notifica** el silencio: un equipo apagado de noche no despierta a nadie.

## Avisos

Van por el circuito de [avisos persistentes](avisos.md) (`database` + `broadcast`), nivel `important`, a los `admin-sucursal` de la sucursal del equipo y a los `admin-empresa` del tenant. Nunca al cajero.

| Aviso | `type` | Cuándo |
|---|---|---|
| Batería baja | `device.battery.low` | ≤ el umbral de aviso de la sucursal sin cargar (severidad `warn`, título «Batería baja»); otra vez al ≤ el umbral urgente (severidad `critical`, título «Se va a apagar»); se rearma al cargar o al subir del umbral de aviso (una lectura nula no rearma) |
| Equipo nuevo | `device.registered` | primer latido de un `device_id` |
| Sin reportar | `device.silent` | > 30 min de silencio dentro de la ventana, una vez por episodio (cualquier latido lo cierra) |
| Versión atrasada | `device.outdated` | versión menor a la publicada hace > 24 h, una vez por versión publicada |

El payload de `DeviceBatteryLow` lleva `severity` (`warn` · `critical`); bajar de un umbral a otro sin haberse cargado vuelve a avisar, subir del crítico al de aviso no.

## Panel

- **Sucursal → Equipos** (`/{tenant}/sucursal/equipos`, `Sucursal\DeviceController`, `BranchDevicesQuery`): tarjetas por equipo con chip de estado, versión (con «al día» / «atrasada · hay X»), batería, último reporte, última venta, IP y contra qué vende. Arriba, la franja «Requieren atención» (`battery_low`, `battery_critical`, sin reportar, atrasados). Abajo, **«Sin registro»**: los `origin_name` de ventas de la Scale API (`origin = 'api'`) de los últimos 30 días que no coinciden con el `name` de ningún equipo conocido, activo o dado de baja. Es una deducción, no una identidad: dos básculas viejas con el mismo nombre se ven como una. Las ventas del mostrador y del hub (`origin_name = 'Administrador'`) no cuentan. El tablero se refresca solo cada 30 s mientras la pestaña está visible (no hay evento de tiempo real para equipos).
- **Empresa → Equipos** (`/{tenant}/empresa/equipos`, `Empresa\DeviceController`): el mismo tablero, agrupado por sucursal.
- **Caja → Equipos** (`/{tenant}/caja/equipos`, `Caja\DeviceController`): el mismo tablero de su sucursal, **solo lectura** (añadido el 2026-09-17). Quien está en el mostrador es quien primero nota una báscula apagada o sin batería. El panel lateral muestra el detalle pero no las acciones, y no hay rutas de escritura en el grupo `caja`. Los avisos de la campana siguen sin llegarle al cajero.
- Tocar una tarjeta abre el panel lateral con el detalle completo (`os`, `model`, «reporta por» / «vende contra») y las tres acciones:

| Acción | Ruta | Efecto |
|---|---|---|
| Renombrar | `PATCH …/equipos/{device}` `{display_name}` | ≤ 100 caracteres; vacío quita el alias |
| Silenciar / reactivar avisos | `PATCH …/equipos/{device}/silencio` | alterna `muted_at` |
| Dar de baja | `DELETE …/equipos/{device}` | pone `retired_at`; desaparece del panel; si vuelve a reportar, reaparece |

Las tres acciones viven en `Concerns\HandlesDeviceWrites`; cada controlador solo decide a qué equipos alcanza (`authorizeDevice`). Un equipo ya dado de baja responde 404 a las tres. En Sucursal, un equipo de otra sucursal da 404; en Empresa no hay restricción de sucursal. El `superadmin` entra a ambos paneles como al resto de secciones (en Sucursal, sin sucursal propia, lo ve vacío).

Componentes: `resources/js/Components/Devices/{DeviceCard,DeviceDetailPanel,DevicesBoard}.vue`. `DevicesBoard` acepta `readonly` (o `routes` nulo) para esconder las acciones.

### Los dos umbrales

Cada sucursal fija a qué porcentaje quiere el aviso y a cuál el urgente (`app/Support/BatteryThresholdRules.php`, una sola regla para los dos formularios):

- **Sucursal → Configuración** (`Sucursal\ConfiguracionController@updateBattery`, `PUT /{tenant}/sucursal/configuracion/bateria`, ruta `sucursal.configuracion.bateria`): tarjeta «Aviso de batería», dos controles de 5 en 5. Ruta aparte de la de métodos de pago porque esa exige `payment_methods_enabled` y se caería si el formulario mandara solo los umbrales.
- **Empresa → Sucursales → Editar** (`GET /{tenant}/empresa/sucursales/{sucursal}/edit`): la misma tarjeta, dentro del formulario general de la sucursal — ahí los dos campos son opcionales pero atados entre sí (`required_with` cruzado), porque el resto del formulario se guarda aunque nadie toque la batería.
- Componente compartido: `resources/js/Components/Devices/BatteryThresholdFields.vue`.

### La franja que no se puede cerrar

En los layouts de caja y de sucursal (`DeviceAlertStrip.vue` + `useDeviceAlerts.js`), justo bajo el encabezado: ámbar si el peor equipo está en `battery_low`, roja si alguno llegó a `battery_critical`. Consulta `GET /{tenant}/equipos/alertas` (ruta `equipos.alertas`, roles `admin-sucursal`, `cajero` y `superadmin`) cada 60 s, en pausa mientras la pestaña no está visible — igual que el tablero de equipos, y por la misma razón: una batería no cambia en segundos. No lleva botón de cerrar: se va sola cuando el equipo se enchufa o sube del umbral de aviso. Un usuario sin sucursal (el `superadmin`) recibe `200` con lista vacía, no un error. Tocarla lleva al panel de Equipos que le corresponde al rol.

## El latido de cada cliente

Cada cliente manda el latido al arrancar, cada 5 min, al cruzar el 20 % y el 10 % de batería y al enchufar o desenchufar. **Esos dos porcentajes siguen fijos dentro de cada app**: la respuesta del latido ya les dice cuáles son los de su sucursal, pero ninguna versión publicada los lee todavía — eso es la entrega 3. Surface (`bascula`) genera su `device_id` y lee `navigator.getBattery()`; Android reutiliza el `device_id` del emparejamiento y, sin nube, late al hub por LAN; los hubs (Electron y Android) se reportan y reenvían el de sus básculas con un endpoint local nuevo anunciado en `cap`, sin subir `protocol_version`. Contrato completo en el spec `docs/superpowers/specs/2026-09-12-registro-de-equipos-design.md` y plan en `docs/superpowers/plans/2026-09-12-registro-de-equipos.md`; implementado en las cuatro apps, tres de ellas publicadas el 2026-09-17.

## Entregas siguientes

Los umbrales por sucursal y la franja de la web ya están en producción — entregas 1 y 2 del spec `docs/superpowers/specs/2026-09-19-avisos-de-bateria-design.md`. Quedan las entregas 3 y 4 de ese mismo spec: que cada equipo guarde el umbral que recibe en el latido y pinte su propia franja —Surface primero, probado en hardware real, que de paso es la primera vez que se comprueba a un equipo latiendo de verdad—, y que los hubs (Electron y Android) repliquen la franja, guarden la última lectura de batería de sus básculas emparejadas y devuelvan `battery_alert` en su propio endpoint LAN, verificado por la suite de conformidad.

## Tests

`tests/Feature/Api/DeviceHeartbeatTest.php` (Scale API), `tests/Feature/Hub/DeviceHeartbeatTest.php` (hub), `tests/Feature/Devices/DeviceAlertsTest.php` (deduplicación de avisos, umbral por sucursal, las dos severidades), `tests/Feature/Devices/DeviceAlertsEndpointTest.php` (`equipos.alertas`, aislamiento por sucursal y rol, `superadmin` sin sucursal), `tests/Feature/Console/DeviceCommandsTest.php` (comandos), `tests/Unit/DeviceStatusTest.php` (estados, `battery_critical` gana a `battery_low` y pierde contra `silent`), `tests/Unit/BatteryThresholdsTest.php` (la comparación de umbrales), `tests/Feature/Sucursal/DevicesTest.php`, `tests/Feature/Empresa/DevicesTest.php`, `tests/Feature/Caja/DevicesTest.php` (paneles), `tests/Feature/Sucursal/ConfiguracionTest.php` y `tests/Feature/Empresa/SucursalesTest.php` (los dos formularios de umbrales, con su validación). `ScaleLegacyContractTest` no se toca.
