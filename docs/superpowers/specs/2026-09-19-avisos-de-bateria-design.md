# Avisos de batería: que nadie se quede sin báscula por una pila

**Estado:** diseño aprobado (2026-09-19) — sin implementar.
**Sobre:** [registro de equipos](2026-09-12-registro-de-equipos-design.md), publicado el 2026-09-17 · doc vivo: [equipos.md](../../modulos/equipos.md)

## El problema

El registro de equipos ya está en producción y **los equipos sí mandan su batería**: la Surface (0.4.2), la tablet Android (1.6.3) y los dos hubs laten cada cinco minutos con su nivel y si están cargando. La nube lo recibe, el panel lo pinta y el aviso de batería baja existe.

Y aun así un cajero se puede quedar sin báscula a media venta, por tres razones:

1. **El umbral está clavado en el código.** 20 % y 10 %, iguales para una Surface nueva y para una tablet de tres años cuya pila se desploma del 30 % al apagón en diez minutos. Nadie puede pedir que le avisen antes.
2. **Avisa dos veces y se calla.** Dos entradas en la campana. Si en ese momento nadie estaba mirando, el equipo sigue descargándose en silencio hasta que se apaga.
3. **Avisa a quien no tiene el cable.** Va al `admin-sucursal` y al `admin-empresa`; nunca al cajero, que es el que está a un metro del enchufe. Y el equipo, que es el primero en saberlo, no dice nada en su propia pantalla.

## Lo que se decidió

| Decisión | Qué queda | Por qué |
|---|---|---|
| Quién se entera | Los administradores **y el cajero** | El que puede enchufarla es el del mostrador. El administrador puede estar en otra sucursal, o dormido. |
| Cómo insiste | **Una franja fija** mientras dure el problema, más los dos avisos de campana de siempre | Repetir el aviso cada quince minutos enseña a ignorar la campana. Una franja que no se puede cerrar no se aprende a ignorar: estorba hasta que la enchufan. |
| Dónde se configura | **Por sucursal**, dos porcentajes | Las pilas envejecen por equipo y por sucursal. Un solo número para toda la empresa obliga al peor caso. |
| Cómo se configura | Dos controles de 5 en 5 en *Sucursal → Configuración* | Sin teclado y sin texto libre que validar en una tablet. |
| Dónde se ve | Web de caja, hub (Electron y Android) y **el propio equipo** | Muchos cajeros trabajan en el hub, no en la web. Y el equipo conoce su propia pila sin preguntarle a nadie: avisa aunque no haya internet, que es cuando peor viene quedarse sin báscula. |
| Cómo se entera la pantalla | **Consulta ligera cada 60 s** | Una batería cambia en minutos. 60 s de retraso no le importa a nadie, y no hay que meter un evento más en el canal `sucursal.{id}`, que ya comparten varios consumidores. |
| Campana del cajero | **No se le abre** | Hoy el cajero no recibe ningún aviso persistente. La franja le dice lo que necesita; decidir qué más le llega a la campana es otra conversación. |

Lo que **no** se hace: estimar cuánto aguanta («~35 min»). Con latidos cada cinco minutos la cuenta sale ruidosa, y una estimación que falla dos veces se deja de creer. El porcentaje y «bajó del 25 % hace 18 min» dicen lo necesario.

## Modelo

Dos columnas en `branches`:

| Columna | Tipo | De fábrica |
|---|---|---|
| `battery_warn_threshold` | `smallint` | `20` |
| `battery_critical_threshold` | `smallint` | `10` |

Validación: múltiplos de 5, entre 5 y 95, y `critical < warn` siempre. Con esos valores por omisión el comportamiento es idéntico al de hoy: ninguna sucursal nota un cambio hasta que decide tocarlo.

`devices.battery_alert_level` **cambia de significado**: hoy guarda el número que disparó el aviso (`20` / `10`); pasa a guardar `warn` / `critical`. Si guardara el número, mover el umbral a media tarde dejaría la marca sin nada con qué compararse y el aviso se repetiría o se perdería. La migración convierte los datos existentes (`20 → warn`, `10 → critical`, nulo se queda nulo).

## Reglas

Sobre las de [equipos.md](../../modulos/equipos.md#avisos), que siguen valiendo (silenciado o de baja no envía nada; una lectura nula ni avisa ni rearma):

| Situación | Qué pasa |
|---|---|
| Llega a `warn` o menos, sin cargar | Estado `battery_low`. Franja ámbar en caja, en el hub y en el propio equipo. Un aviso de campana a los administradores. |
| Llega a `critical` o menos, sin cargar | Estado `battery_critical`. La misma franja en rojo y con otro texto. Segundo aviso de campana. |
| Lo enchufan, o sube de `warn` | La franja se va sola: al instante en el propio equipo, al siguiente latido en las demás pantallas. Las marcas se rearman. |
| Sin lectura de batería | Nada. Un equipo de escritorio enchufado a la pared no debe molestar nunca. |
| Silenciado o de baja | Nada, tampoco franja. El silencio calla todo, no solo la campana. |
| Bajan el umbral por debajo del nivel actual | El equipo deja de estar en alerta en el siguiente latido y la marca se limpia. No se manda un «ya estás bien». |

La comparación es `<=`, como hoy (`level > warn` rearma). Con 20 y 10 el comportamiento es idéntico al actual, literalmente.

`battery_critical` se evalúa **exactamente donde hoy se evalúa `battery_low`**: después de `retired`, `silent` y `stale`, y gana a `battery_low`. Es decir, **exige estar en línea**. Un equipo que lleva veinte minutos callado con una última lectura del 5 % se sigue viendo `silent`, no `battery_critical`: una lectura vieja no dice nada del presente, y esa regla ya está documentada. El orden queda `retired → silent → stale → battery_critical → battery_low → online`.

## Superficies

### Nube

Hoy el `20` está escrito en **tres** sitios, no en uno: `DeviceAlertService::checkBattery()` (a quién avisar), el accessor `Device::status` (`app/Models/Device.php:107`, qué estado se pinta) y `DeviceCard.vue:42` (de qué color va la barrita). Los tres pasan a leer los umbrales de la sucursal.

- **Un value object `BatteryThresholds`** (`warn`, `critical`) que se construye desde una `Branch` y cae a 20/10 si falta. Es el único que sabe comparar un nivel contra un umbral.
- `Device::statusFor(BatteryThresholds $t)` es la forma con la que se decide de verdad. El accessor `status` se queda como atajo y hace `loadMissing('branch')`, para que ningún sitio existente deje de compilar; **las dos consultas cargan la sucursal explícitamente** (`with('branch')`) para no caer en un N+1 — el panel de Empresa presenta N sucursales y hoy no hace eager load.
- `DeviceAlertService::checkBattery()` lee los mismos umbrales. Sigue siendo el único sitio donde se decide **a quién se avisa**.
- `BranchDevicesQuery:50` arma la franja «Requieren atención» filtrando por `['battery_low', 'silent']`: hay que añadir `battery_critical` o un equipo crítico desaparecería justo de esa lista.
- `DeviceBatteryLow` gana `severity` (`warn` | `critical`) en el payload; el título y el cuerpo cambian con ella («Batería baja» / «Se va a apagar»). Una sola clase de notificación: son el mismo hecho con distinta urgencia.
- `BranchDeviceAlertsQuery`: equipos de una sucursal en `battery_low` o `battery_critical`, ni silenciados ni de baja. Devuelve lo mínimo — `device_id`, nombre mostrado, `battery_level`, `severity` — porque va a pintarse en una franja, no en un panel.
- `severity` sale del **estado derivado** (nivel vivo contra umbrales), no de la marca `battery_alert_level`, que solo sirve para no repetir la campana. Caso concreto: un equipo marcado `critical` que sube del 8 % al 15 % con umbrales 20/10 no vuelve a sonar en la campana (la marca sigue puesta) pero su franja pasa de roja a ámbar. Es lo que se quiere; queda escrito para que no se resuelva al revés.

Dos puertas a la misma consulta:

| Ruta | Guardia | Quién |
|---|---|---|
| `GET /{tenant}/equipos/alertas` | sesión + tenant | cajero y admin‑sucursal, su propia sucursal; `superadmin` entra igual |
| `GET /api/v1/hub/devices/alerts` | Sanctum + `hub.role` | el hub, la sucursal de su token |

Un usuario **sin sucursal** —el `superadmin`, que entra a los dos paneles y no tiene `branch_id`— recibe **200 con la lista vacía**, nunca un 403: la franja se monta en el layout y estaría disparando un error cada 60 segundos contra una pantalla que él sí puede ver.

### Scale API — solo aditivo

La respuesta de `POST /api/v1/devices/heartbeat`, que hoy devuelve el eco del registro, gana un campo:

```json
{ "data": { "device_id": "…", "status": "battery_critical", "battery_alert": { "warn": 25, "critical": 10 } } }
```

Dentro de `data`, que es como `DeviceHeartbeatController::respond()` envuelve todo: lo van a parsear cuatro apps en cuatro repos y no conviene que cada una adivine. Campo nuevo en una respuesta: las básculas 0.4.2 ya publicadas lo ignoran y siguen vendiendo igual. **No se toca nada de lo existente.**

Un apunte de compatibilidad: el campo `status`, que ya existe, empieza a poder devolver un valor nuevo (`battery_critical`). Hoy ningún cliente desplegado lo interpreta, así que no rompe nada — pero que quede escrito, para que dentro de seis meses no se lea como una incompatibilidad silenciosa.

### Web

- `DeviceAlertStrip.vue` + `useDeviceAlerts` (consulta cada 60 s, en pausa mientras la pestaña no está visible, como el tablero de equipos). Montado en `CajeroLayout` y `SucursalLayout`, bajo el encabezado: empuja el contenido, no tapa nada y no se puede cerrar.
- Varios equipos en alerta: una sola franja, «2 equipos con poca batería», y al tocarla lleva al panel de Equipos.
- **Dos superficies, una sola regla.** El grupo `/sucursal` es `role:admin-sucursal|superadmin` y su controlador resuelve la sucursal con `Auth::user()->branch_id`: el `admin-empresa` **no entra ahí**, y no tiene `branch_id`. Así que:
  - *Sucursal → Configuración* gana la tarjeta «Aviso de batería» con los dos controles de 5 en 5, y una ruta propia `PUT /{tenant}/sucursal/configuracion/bateria`. Ruta aparte para no tocar la validación de `payment_methods_enabled`, que hoy es `required|array|min:1` y se caería si el formulario mandara solo los umbrales.
  - *Empresa → Sucursales → Editar* gana los mismos dos campos, donde el `admin-empresa` ya administra el resto de la sucursal.
  - La validación vive en **un solo sitio** (una regla compartida por los dos formularios), o el día que alguien cambie el tope de 95 lo cambiará en uno de los dos.
- La tarjeta del equipo muestra «bajó del 25 % hace 18 min» y pinta el chip en rojo cuando es crítico.

### Hub y equipos

- El hub replica `DeviceAlertStrip` con su propio Tailwind (la web es la fuente de verdad visual) y lee `GET /api/v1/hub/devices/alerts`.
- Cada equipo compara su propia batería con el umbral que recibió en el último latido y pinta su franja sin consultar a nadie. Si nunca ha podido latir, usa 20/10.
- Los clientes ya leen la batería y ya laten: lo que se añade es **guardar el umbral que viene en la respuesta** y **pintar la franja**. Nada del transporte cambia.

**El camino LAN, con nombre y forma.** El endpoint local del hub (`POST /api/v1/devices/heartbeat` con `X-Api-Key`, capacidad `heartbeat`, ya anunciada en `cap` por las dos implementaciones) responde hoy `202 {data:{device_id, accepted:true}}`. Gana un campo dentro de `data`:

```json
{ "data": { "device_id": "…", "accepted": true, "battery_alert": { "warn": 25, "critical": 10 } } }
```

- **No se crea una capacidad nueva ni se sube `protocol_version`**: es un campo más en una respuesta que ya existe. Una báscula vieja que no lo lea sigue latiendo igual.
- El hub sirve los últimos umbrales que le dio la nube. Los **guarda en su SQLite**, no en memoria: si solo vivieran en RAM, el escenario que este campo resuelve —el hub arrancando sin internet— sería justo el que lo perdería. Sin ninguno guardado, sirve 20/10.
- Para pintar su franja sin internet, el hub **guarda la última lectura de batería de cada báscula emparejada** (nivel, si carga, cuándo llegó). Hoy reenvía el latido y no se queda nada, así que esto es una tabla nueva de una fila por equipo.
- Los dos hubs se comportan igual o falla la **suite de conformidad**, que corre los mismos casos contra Electron y Kotlin. El caso nuevo entra ahí, bajo la capacidad `heartbeat`.

## Entregas

1. **Umbrales.** Migración (columnas nuevas y `battery_alert_level` a texto), `BatteryThresholds`, los tres sitios que tenían el 20 escrito, estado `battery_critical` en el accessor y en la franja «Requieren atención», `severity` en la notificación, las dos superficies de edición con su regla compartida, y el doc vivo al día.
2. **La franja en la web.** `BranchDeviceAlertsQuery`, las dos rutas, `useDeviceAlerts`, `DeviceAlertStrip` en los dos layouts. `battery_alert` en la respuesta de los dos endpoints de latido de la nube.
3. **Surface.** Guarda el umbral que recibe y pinta su franja. **Se prueba en hardware antes de seguir** — y de paso se ve por fin latir a un equipo de verdad, que nunca se ha comprobado.
4. **Android y hubs.** Lo mismo en la tablet. El hub guarda umbrales y últimas lecturas, los sirve en su endpoint local, pinta su franja y lee el endpoint de alertas; el caso nuevo entra en la suite de conformidad y los dos hubs lo pasan.

Cada entrega es publicable por su cuenta: la 1 y la 2 mejoran la web sin tocar ninguna app; las apps siguen funcionando igual hasta que se actualicen. Las entregas 3 y 4 terminan con **versión nueva y publicación por tag** de cada app (Surface, Android, hub Electron, hub Android), que es donde este ecosistema suele tropezar.

## Documentación

Cada entrega deja al día el doc vivo que toca (CLAUDE.md lo exige, y aquí son cinco):

| Doc | Qué cambia |
|---|---|
| `modulos/equipos.md` | Estados (`battery_critical`, y el 20 % deja de ser fijo), Avisos (umbral por sucursal), el panel y las rutas nuevas — **y la cabecera de estado, que quedó desfasada** |
| `modulos/avisos.md` | La tabla «Avisos existentes» dice «baja del 20 % sin cargar»; pasa a nombrar el umbral de la sucursal y las dos severidades |
| `api/endpoints.md` | `battery_alert` en la respuesta del latido de la Scale API |
| `api/hub.md` | Lo mismo en el latido del hub, más `GET /api/v1/hub/devices/alerts` |
| Docs del protocolo local (hub y hub‑android) | El campo nuevo en la respuesta del endpoint LAN y su caso de conformidad |

## Deuda que arrastra este trabajo

`docs/modulos/equipos.md` en `main` todavía dice «los clientes todavía no mandan el latido» y deja las cuatro entregas como «siguientes». Se publicaron el 2026-09-17 y el doc no se actualizó. La entrega 1 lo corrige.

## Pruebas

- `DeviceAlertsTest`: umbral por sucursal, las dos severidades, que bajar el umbral no dispare un aviso, que un equipo silenciado no salga en las alertas.
- `DeviceHeartbeatTest` (Scale y hub): la respuesta trae `battery_alert` con los umbrales de la sucursal.
- `ScaleLegacyContractTest`: intacto. Si cambia, algo se rompió.
- Nuevo `DeviceAlertsEndpointTest`: cada rol ve solo su sucursal; un cajero de otra sucursal no ve nada; un `superadmin` sin sucursal recibe 200 con lista vacía.
- `DeviceStatusTest`: `battery_critical` gana a `battery_low`, y un equipo callado con lectura vieja del 5 % sigue siendo `silent`.
- Validación: los dos formularios (sucursal y empresa) rechazan `critical >= warn`, valores fuera de 5–95 y los que no son múltiplos de 5.
- Conformidad hub Electron ↔ hub Android: el endpoint local devuelve `battery_alert` en `data` y los dos se comportan igual.
- Hardware: una Surface descargándose de verdad, con el cargador fuera, viendo aparecer la franja en la báscula y en la caja.

## Riesgos

- **Una franja que no se puede cerrar puede volverse ruido** si una sucursal deja un equipo desenchufado a propósito. La salida ya existe: silenciar ese equipo desde el panel.
- **Un umbral alto llena la pantalla de franjas.** Por eso el tope es 95 % y el control va de 5 en 5: hay que quererlo.
- **El hub sin internet no conoce los umbrales nuevos** hasta que vuelva a sincronizar. Usa los últimos que recibió, no los de fábrica.
- **Los equipos en hardware todavía no se han visto latir.** El registro de equipos se publicó sin prueba de campo; si algo falla ahí, se nota al probar la entrega 3.
