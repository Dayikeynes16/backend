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
| Baja de `warn` sin cargar | Estado `battery_low`. Franja ámbar en caja, en el hub y en el propio equipo. Un aviso de campana a los administradores. |
| Baja de `critical` sin cargar | Estado `battery_critical`. La misma franja en rojo y con otro texto. Segundo aviso de campana. |
| Lo enchufan, o sube de `warn` | La franja se va sola: al instante en el propio equipo, al siguiente latido en las demás pantallas. Las marcas se rearman. |
| Sin lectura de batería | Nada. Un equipo de escritorio enchufado a la pared no debe molestar nunca. |
| Silenciado o de baja | Nada, tampoco franja. El silencio calla todo, no solo la campana. |
| Bajan el umbral por debajo del nivel actual | El equipo deja de estar en alerta en el siguiente latido y la marca se limpia. No se manda un «ya estás bien». |

El estado nuevo `battery_critical` gana a `battery_low` y se coloca entre `battery_low` y `silent` en el orden del accessor `status`. `retired` sigue ganando a todo.

## Superficies

### Nube

- `DeviceAlertService::checkBattery()` lee los umbrales de la sucursal del equipo en vez de las constantes. Sigue siendo el único sitio donde se decide.
- `DeviceBatteryLow` gana `severity` (`warn` | `critical`) en el payload; el título y el cuerpo cambian con ella («Batería baja» / «Se va a apagar»). Una sola clase de notificación: son el mismo hecho con distinta urgencia.
- `BranchDeviceAlertsQuery`: equipos de una sucursal en `battery_low` o `battery_critical`, ni silenciados ni de baja. Devuelve lo mínimo — `device_id`, nombre mostrado, `battery_level`, `severity` — porque va a pintarse en una franja, no en un panel.

Dos puertas a la misma consulta:

| Ruta | Guardia | Quién |
|---|---|---|
| `GET /{tenant}/equipos/alertas` | sesión + tenant | cajero y admin‑sucursal, su propia sucursal |
| `GET /api/v1/hub/devices/alerts` | Sanctum + `hub.role` | el hub, la sucursal de su token |

### Scale API — solo aditivo

La respuesta de `POST /api/v1/devices/heartbeat`, que hoy devuelve el eco del registro, gana un campo:

```json
{ "battery_alert": { "warn": 25, "critical": 10 } }
```

Campo nuevo en una respuesta: las básculas 0.4.2 ya publicadas lo ignoran y siguen vendiendo igual. **No se toca nada de lo existente** y no se sube `protocol_version` en el hub; el hub lo reexpone a sus básculas en la respuesta de su endpoint local de latido, ya anunciado en `cap` como `heartbeat`.

### Web

- `DeviceAlertStrip.vue` + `useDeviceAlerts` (consulta cada 60 s, en pausa mientras la pestaña no está visible, como el tablero de equipos). Montado en `CajeroLayout` y `SucursalLayout`, bajo el encabezado: empuja el contenido, no tapa nada y no se puede cerrar.
- Varios equipos en alerta: una sola franja, «2 equipos con poca batería», y al tocarla lleva al panel de Equipos.
- *Sucursal → Configuración* gana la tarjeta «Aviso de batería» con los dos controles de 5 en 5. La edita el `admin-sucursal` —ahí ya gestiona lo operativo del POS— y también el `admin-empresa` desde la sucursal.
- La tarjeta del equipo muestra «bajó del 25 % hace 18 min» y pinta el chip en rojo cuando es crítico.

### Hub y equipos

- El hub replica `DeviceAlertStrip` con su propio Tailwind (la web es la fuente de verdad visual) y lee `GET /api/v1/hub/devices/alerts`. Sin internet, muestra lo que sepa de las básculas que le latieron por LAN.
- Cada equipo compara su propia batería con el umbral que recibió en el último latido y pinta su franja sin consultar a nadie. Si nunca ha podido latir, usa 20/10.
- Los clientes ya leen la batería y ya laten: lo que se añade es **guardar el umbral que viene en la respuesta** y **pintar la franja**. Nada del transporte cambia.

## Entregas

1. **Umbrales.** Migración (columnas nuevas y `battery_alert_level` a texto), validación, tarjeta en *Configuración*, `DeviceAlertService` leyéndolos, estado `battery_critical`, `severity` en la notificación.
2. **La franja en la web.** `BranchDeviceAlertsQuery`, las dos rutas, `useDeviceAlerts`, `DeviceAlertStrip` en los dos layouts. `battery_alert` en la respuesta de los dos endpoints de latido.
3. **Surface.** Guarda el umbral que recibe y pinta su franja. **Se prueba en hardware antes de seguir.**
4. **Android y hubs.** Lo mismo en la tablet; el hub pinta su franja, sirve el umbral a sus básculas y lee el endpoint de alertas.

Cada entrega es publicable por su cuenta: la 1 y la 2 mejoran la web sin tocar ninguna app; las apps siguen funcionando igual hasta que se actualicen.

## Deuda que arrastra este trabajo

`docs/modulos/equipos.md` en `main` todavía dice «los clientes todavía no mandan el latido» y deja las cuatro entregas como «siguientes». Se publicaron el 2026-09-17 y el doc no se actualizó. La entrega 1 lo corrige.

## Pruebas

- `DeviceAlertsTest`: umbral por sucursal, las dos severidades, que bajar el umbral no dispare un aviso, que un equipo silenciado no salga en las alertas.
- `DeviceHeartbeatTest` (Scale y hub): la respuesta trae `battery_alert` con los umbrales de la sucursal.
- `ScaleLegacyContractTest`: intacto. Si cambia, algo se rompió.
- Nuevo `DeviceAlertsEndpointTest`: cada rol ve solo su sucursal; un cajero de otra sucursal no ve nada.
- Hardware: una Surface descargándose de verdad, con el cargador fuera, viendo aparecer la franja en la báscula y en la caja.

## Riesgos

- **Una franja que no se puede cerrar puede volverse ruido** si una sucursal deja un equipo desenchufado a propósito. La salida ya existe: silenciar ese equipo desde el panel.
- **Un umbral alto llena la pantalla de franjas.** Por eso el tope es 95 % y el control va de 5 en 5: hay que quererlo.
- **El hub sin internet no conoce los umbrales nuevos** hasta que vuelva a sincronizar. Usa los últimos que recibió, no los de fábrica.
- **Los equipos en hardware todavía no se han visto latir.** El registro de equipos se publicó sin prueba de campo; si algo falla ahí, se nota al probar la entrega 3.
