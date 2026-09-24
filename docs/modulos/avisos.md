# Avisos (notificaciones persistentes)

Lo que un usuario tiene que enterarse **aunque no estuviera mirando la pantalla**.

## Por qué existe

El WebSocket entrega al instante pero no guarda nada: quien estaba desconectado en ese segundo no se entera nunca. Para un beep de venta nueva da igual —la venta está en la lista y se ve al entrar—, pero no para algo que espera una decisión.

El caso que lo motivó: un cajero pedía cancelar una venta de varios miles y **no salía ningún aviso**. El administrador no se enteraba hasta entrar por su cuenta a la pantalla de cancelaciones, y el cajero se quedaba esperando un desenlace del que tampoco se le informaba.

La regla que queda: **el evento avisa, la fila garantiza.**

## Responsabilidades

- Guardar avisos dirigidos a un usuario concreto y entregarlos en vivo si está conectado.
- Que se puedan leer después, marcar como leídos y contar los pendientes.

**No hace:** no manda correo (para eso están las `Notification` con canal `mail`), no agrupa ni repite, no caduca solos.

## Cómo funciona

Es el sistema estándar de Laravel, sin capa propia encima:

1. Una `Notification` con `via() === ['database', 'broadcast']`.
2. `database` escribe en la tabla `notifications`.
3. `broadcast` la emite por el canal privado `App.Models.User.{id}`, que ya estaba declarado y autorizado en `routes/channels.php`.
4. La isla (`NotificationIsland.vue`, ver [más abajo](#la-isla-en-la-web)) lee por HTTP al arrancar —el socket no reenvía lo anterior— y escucha el canal para lo que llegue después.

### El broadcast sale síncrono

Toda notificación devuelve `toBroadcast()` con `->onConnection('sync')`. La razón: `BroadcastNotificationCreated`, el evento que Laravel dispara por el canal `broadcast`, es `ShouldBroadcast` (encolado), y **producción no corre colas** ([reverb-websockets](../arquitectura/reverb-websockets.md)). Sin `sync` la fila se guardaba pero el broadcast se quedaba en la cola para siempre: ningún aviso llegaba en vivo, y no se notaba porque el hub lo tapaba con su sondeo y la web al recargar.

Síncrono significa que **el envío puede lanzar** con Reverb caído. Por eso cada `notify()` lleva su propio `try/catch` **por destinatario, no alrededor del bucle** (`SaleCancellationNotifier`, `DeviceAlertService`, `AgendaReminderNotifier`): envolviendo el `foreach` entero, el primer broadcast que fallaba cortaba el bucle y los siguientes destinatarios se quedaban sin fila —y en los avisos de equipo, con la marca del equipo ya puesta y sin reintento—. La fila de la base se escribe antes que el broadcast, así que un Reverb caído deja el aviso en la bandeja igualmente.

### `broadcastType()`

Cada notificación declara `broadcastType()` devolviendo el mismo `type` de su `toArray()`. Sin él, Laravel pone el nombre de la clase y el aviso en vivo llegaba con un `type` distinto del guardado. El nombre del evento (`broadcastAs`) no cambia, así que `Echo.notification()` y el hub no se enteran.

### Aislamiento

No hay `tenant_id` en la tabla, **a propósito**: el aislamiento lo da `notifiable_id`, que es un usuario y ya pertenece a un tenant y a una sucursal. La relación `$user->notifications()` no puede alcanzar las de otro. Añadir la columna sería un segundo camino para lo mismo, y dos caminos se contradicen tarde o temprano.

Por eso las rutas viven **fuera** del prefijo de tenant: no hay nada que aislar más allá del propio usuario, y la isla tiene que funcionar igual en los cuatro paneles.

### Niveles

El campo `level` del payload decide cuánto grita el aviso:

| Nivel | Significa | Ejemplo |
|-------|-----------|---------|
| `action` | Algo espera una decisión suya | Solicitud de cancelación por resolver, recordatorio vencido |
| `important` | Ya ocurrió y le cambia el trabajo | Su cancelación fue aprobada, le asignaron una tarea |
| `info` | Contexto, se lee de pasada | Su cancelación fue rechazada |

## Rutas

Todas bajo `auth`, sin prefijo de tenant.

| Método | Ruta | Nombre | Qué hace |
|--------|------|--------|----------|
| GET | `/avisos` | `notifications.index` | Últimos 20 del usuario + contador de no leídos |
| PATCH | `/avisos/{notification}/leido` | `notifications.read` | Marca uno (404 si no es suyo) |
| PATCH | `/avisos/leidos` | `notifications.read-all` | Marca todos los suyos |

## Avisos existentes

| Notificación | `type` | Va a | Cuándo |
|--------------|--------|------|--------|
| `SaleCancellationRequested` | `sale.cancellation.requested` | Administradores de la sucursal de la venta | Un cajero pide cancelar |
| `SaleCancellationResolved` | `sale.cancellation.approved` / `rejected` | El cajero que la pidió | El administrador aprueba o rechaza |
| `DeviceBatteryLow` | `device.*` | Administradores de la sucursal del equipo + admin-empresa | Un equipo baja del umbral de aviso de su sucursal sin cargar; otra vez al umbral urgente. El payload lleva `severity` — [equipos](equipos.md) |
| `DeviceRegistered` | `device.*` | Los mismos | Primer latido de un equipo nuevo |
| `DeviceSilent` | `device.*` | Los mismos | Un equipo lleva > 30 min sin reportar dentro de la ventana 08–20 h |
| `DeviceOutdated` | `device.*` | Los mismos | Un equipo sigue en una versión menor a la publicada hace > 24 h |
| `AgendaReminderDue` | `agenda.reminder.due` (`action`) | El asignado del ítem o, si no hay, quien lo creó | Vence un recordatorio de la agenda — ver abajo |
| `AgendaItemAssignedNotice` | `agenda.item.assigned` (`important`) | El nuevo asignado, nunca quien asigna | Se crea una tarea asignada a otra persona, o se reasigna al editarla |

Los cinco puntos de entrada del circuito de cancelaciones (mesa de sucursal, mesa de caja y la API del hub para las tres acciones) pasan por `SaleCancellationNotifier`, que resuelve destinatarios en un solo sitio. Repetir esa regla cinco veces es la forma habitual de que una de las cinco se quede sin avisar.

## La agenda como fuente

Desde 2026-09-24 la agenda no tiene campana propia: un recordatorio que vence y una tarea asignada son **avisos normales** (fila + broadcast). Las alertas calculadas (cuentas por pagar, fiados, turnos sin cerrar, API keys) y las tareas atrasadas **no** entran: son estado, no eventos, y se quedan en la pantalla [Agenda](agenda.md) y en el widget de los dashboards.

Todo pasa por `App\Services\Agenda\AgendaReminderNotifier` (el equivalente de `SaleCancellationNotifier`):

- **Destinatario (`recipient()`):** uno solo — el asignado o, si no hay, quien creó el ítem, siempre del mismo tenant. Son los únicos a quienes `AgendaItemPolicy` deja completar o posponer; mandarlo a toda la sucursal daría botones que responden 403, y un «Visto» de cualquiera se lo descartaría al dueño. Las tareas de sucursal/empresa siguen visibles para todos en la pantalla Agenda.
- **Comando `agenda:dispatch-reminders`** (`DispatchAgendaRemindersCommand`), cada minuto con `withoutOverlapping()` en `bootstrap/app.php`. Busca ítems activos con `remind_at <= now()`, `reminder_seen_at` y `reminder_notified_at` nulos, y los recorre con `lazyById(200)` (no `each()`: avisar saca al ítem del filtro y la paginación por offset se saltaría ítems). Consulta `AgendaItem` tal cual —en consola `TenantScope` no filtra, como `CheckDevicesCommand`— sin `withoutGlobalScopes()`, que quitaría también `SoftDeletes`.
- **Un aviso por vencimiento:** `remind()` **primero** fija `reminder_notified_at` y **después** notifica. Si el envío falla se registra y no se reintenta (con Reverb caído, reintentar cada minuto duplicaría filas).
- **Índice parcial** `agenda_items_pending_reminders_idx` sobre `remind_at`, limitado a recordatorios vivos sin avisar ni ver: la consulta de cada minuto no crece con los años de tareas hechas.
- **Migración aditiva:** columna `agenda_items.reminder_notified_at`; al desplegar marca como avisados los recordatorios de hace más de 24 h (`backfillOld()`), para no anunciar de golpe recordatorios de hace días.
- **Cierre al atender (`close()`):** completar, cancelar, posponer, marcar visto, borrar o reasignar el ítem en `AgendaController` marca leídos sus avisos `agenda.reminder.due` pendientes. Así, atenderlo en la pantalla Agenda apaga también la isla. La consulta usa `whereRaw("(data::jsonb->>'item_id')::bigint = ?")`: `notifications.data` es `text` en PostgreSQL.
- **Rearmar (`rearm()`):** posponer, cambiar `remind_at` o cambiar el asignado limpian `reminder_notified_at` y `reminder_seen_at`, para que el comando vuelva a avisar (al nuevo destinatario, si ya venció). `update()` es un `PUT` que siempre trae los campos: sólo rearma si el valor **cambió de verdad** — el asignado con `wasChanged()`, la hora **comparada al minuto** (el modal manda `datetime-local` sin segundos y un posponer la dejó con ellos; guardar el título no debe reavisar).
- **Recurrencia:** la siguiente ocurrencia que crea `complete()` nace sin `reminder_notified_at` ni `reminder_seen_at`.

## La isla en la web

Sustituye a las dos campanas que había en el encabezado (`NotificationBell` y la `AgendaBell` de la agenda, ambas tituladas «Avisos»). Es un port de la isla del hub (`carniceria-hub/docs/avisos.md`) con **las mismas reglas**: estados reposo / anunciando / bandeja, niveles (`action` se queda, `important` 6 s con pausa al pasar el puntero, `info` sólo latido), cola con `action` primero y «+N más», primera lectura sin anunciar, sondeo **20 s / 4 s** según el estado de Echo, relectura al volver a la pestaña, marcado optimista, pie «Sin conexión · actualizado hace N min» y «centrada si cabe».

| Pieza | Qué hace |
|-------|----------|
| `resources/js/lib/notificationsCore.js` | Puro: niveles, orden, agrupado, colocación (`islandPlacement`) |
| `resources/js/lib/notificationRoutes.js` | `routeFor()`: adónde lleva cada `type` según el rol |
| `resources/js/lib/chime.js` | El sonido del anuncio |
| `resources/js/composables/useNotifications.js` | Motor `createNotificationCenter(deps)` + singleton `useNotifications()` |
| `resources/js/Components/Notifications/NotificationIsland.vue`, `NotificationRow.vue`, `IslandIcon.vue` | La isla y sus filas |

- **Datos:** `fetch` a `notifications.index` / `read` / `read-all`. En vivo, `Echo.private('App.Models.User.{id}').notification(...)`, **como señal**: el evento dispara una relectura de `GET /avisos`, no se pinta tal cual. Estado del socket con `trackConnection` de `lib/realtimeState.js`.
- **Ciclo de vida:** los layouts se vuelven a montar en cada navegación de Inertia, así que el motor es un singleton que **arranca una vez por usuario** (`ensureStarted(user)`) y **no se detiene al desmontar** la isla (ni `stop()` ni `Echo.leave()`): un anuncio en curso sobrevive a la navegación. Cerrar sesión recarga la página y lo reinicia.
- **Montaje:** va en `EmpresaLayout`, `SucursalLayout`, `CajeroLayout` y `AuthenticatedLayout`, como primer hijo del grupo de botones de la derecha. El layout marca el encabezado con `data-island-header` y el título con `data-island-title`, y la isla mide con eso. A diferencia del hub, **acoplada ocupa su hueco dentro del grupo derecho** (empuja al título en vez de flotar encima de sus botones); sólo lo que se abre flota. En teléfono (`< sm`) va siempre acoplada y, abierta, ocupa el ancho de la pantalla menos 16 px por lado.
- **Destinos:** `sale.cancellation.requested` → cancelaciones de sucursal; `approved`/`rejected` → la mesa de trabajo del rol; `device.*` → el panel de equipos del rol; `agenda.*` → `agenda.index`; otro → sin destino («Entendido»). Sin `tenant_slug` (superadmin en su perfil) nada tiene destino.
- **Acciones del recordatorio:** en el anuncio y en la fila, **Hecho** (`agenda.complete`), **+30 min** (`agenda.snooze`) y **Visto** (`agenda.visto`). Tras cualquiera, la isla recoge el anuncio y relee: el backend ya cerró los avisos del ítem.
- **Tailwind** escanea también `resources/js/**/*.js`: los colores de nivel viven en `LEVEL_STYLE` de `notificationsCore.js` y sin eso no se generaban. Los tokens de movimiento (`duration-fast/normal/slow`, `ease-enter/exit/state`) son los del hub.
- `DeviceAlertStrip` (la franja de equipos) sigue aparte.

## Añadir un aviso nuevo

1. Una clase en `app/Notifications/` con `via()` devolviendo `['database', 'broadcast']`.
2. `toArray()` con `type`, `level`, `title`, `body` y los ids que necesite la UI.
3. `broadcastType()` devolviendo el mismo `type`, y `toBroadcast()` devolviendo `(new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync')`. Sin `sync` no sale en vivo (producción no corre colas).
4. Si el aviso necesita destino calculado (roles, sucursal), un servicio como `SaleCancellationNotifier` en vez de repetir la consulta en cada controlador.
5. Si el aviso debe llevar a algún sitio, añadir el caso en `routeFor()` de `resources/js/lib/notificationRoutes.js` (y en su test).

**Nunca dejes que el envío tumbe la operación.** Cuando se notifica, el trabajo real ya está guardado; un fallo se registra y se sigue. Con el broadcast síncrono esto no es teórico: el `try/catch` va **alrededor de cada `notify()`**, no del bucle de destinatarios.

## Límites conocidos

- **La bandeja pide 20 y no pagina.** Suficiente hoy; si un usuario acumula muchos, los viejos sólo se ven marcando leídos.
- **Nada las borra.** No hay limpieza programada de avisos antiguos.
- **Reverb colgado añade latencia.** Con el broadcast síncrono, un Reverb que cuelga (en vez de rechazar) suma su espera a cada envío: a la petición que dispara el aviso y al comando por minuto. No hay timeout propio en el cliente; es la misma exposición que ya tiene `SafeBroadcast`.
- **El hub muestra los recordatorios sin sus acciones.** El Hub Electron lee la misma bandeja con su isla desde la 1.3.0 (ver `carniceria-hub/docs/avisos.md`), así que los avisos de agenda le llegan solos; pero `agenda.reminder.due` no tiene destino allí y muestra «Entendido», que marca leído sin completar ni posponer el ítem. Llevar Hecho / +30 min al hub requiere endpoints de agenda en `/api/v1/hub`.
- **Pestaña oculta:** la isla sólo suena; no hay notificación del sistema del navegador.
- **El admin-empresa las recibe si es destinatario**, pero hoy ninguna lo tiene como tal: las cancelaciones son cosa de sucursal.

## Tests

- `tests/Feature/NotificationInboxTest.php` (la bandeja sólo devuelve lo propio) y `tests/Feature/Hub/NotificationInboxTest.php` (la misma bandeja bajo Sanctum, con el aislamiento intacto).
- `tests/Feature/Sucursal/CancelacionAvisosTest.php`: los tres momentos del circuito, aislamiento entre sucursales y entre empresas, y que un fallo al notificar no rompe la solicitud.
- `tests/Feature/Notifications/NotificationBroadcastTest.php`: cada notificación tiene `broadcastType()` igual a su `type` y sale por `sync`; con el broadcast fallando, **todos** los destinatarios reciben su fila y la petición no se rompe.
- Agenda: `AgendaReminderNotifierTest` (destinatario, envío, cierre, rearmado), `DispatchAgendaRemindersCommandTest` (una sola vez, filtros, varios tenants, fallo de envío), `AgendaReminderLifecycleTest` (cada acción del controlador cierra/rearma; un `PUT` sin cambios no toca nada) y `AgendaReminderMigrationTest` (columna, backfill e índice), en `tests/Feature/Agenda/`.
- JS (Vitest): `tests/js/notificationsCore.test.js`, `notificationRoutes.test.js` y `useNotifications.test.js` (el motor, con los casos del hub adaptados).
