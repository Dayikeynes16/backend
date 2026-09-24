# La isla en la web, y la agenda como fuente de avisos

- **Estado:** Diseño aprobado (2026-09-24), pendiente de plan.
- **Fecha:** 2026-09-24
- **Repos afectados:** `carniceria-saas` (backend + frontend web). `carniceria-hub`: **sin cambios obligatorios** (§6). `bascula`, `bascula-android`, `hub-android`: sin cambios.
- **Viene de:** la isla del hub (`carniceria-hub/docs/superpowers/specs/2026-09-23-avisos-isla-design.md`, PR hub#36). Este spec la lleva a la web con **las mismas reglas** y no las repite: donde dice «como en el hub», manda ese spec.

---

## 1. El problema

La web tiene **dos campanas juntas** en el encabezado de Empresa, Sucursal y Caja, y las dos se titulan «Avisos»:

| | `NotificationBell` | `AgendaBell` |
|---|---|---|
| Qué muestra | Avisos guardados para el usuario (cancelaciones, batería, equipos) | Recordatorios vencidos, tareas atrasadas y alertas calculadas (cuentas por pagar, fiados, turnos sin cerrar, API keys) |
| Naturaleza | Eventos: pasan, se leen | Estado: siguen ahí hasta resolverse |
| En vivo | Sí (Echo) | No: se consulta al montar y al abrir; el polling se quitó el 2026-08-21 por caro |
| Defecto | Los avisos en vivo no enlazan: el `type` llega pisado por el nombre de clase | Un recordatorio que vence con la app abierta no aparece; `AgendaItemAssigned` se emite y nadie lo escucha |

Y el hub ya tiene la isla; la web no.

## 2. Decisiones

| # | Decisión | Por qué |
|---|----------|---------|
| D1 | **La agenda es una fuente más de avisos.** Un recordatorio que vence y una tarea asignada crean un aviso normal (fila + broadcast), como una cancelación. | Un solo modelo. «El evento avisa, la fila garantiza» ya resuelve lo que a la agenda le faltaba: llegar en vivo y no perderse si no estabas. |
| D2 | **Las alertas calculadas y las tareas atrasadas no entran en la isla.** Se quedan en la pantalla Agenda (pestaña Alertas) y en el widget «Agenda — alertas» de los dashboards. | Son estado, no eventos. Guardarlas como fila duplicaría una verdad que se calcula al momento. Y se elimina la consulta cara que la campana de agenda hacía en cada carga. |
| D3 | **Un recordatorio es `action`** y lleva sus acciones en la isla: **Hecho**, **+30 min**, **Visto**. | Un recordatorio que se recoge solo no recuerda nada. |
| D4 | **La isla web es la del hub**: mismos estados, niveles, cola, movimiento, «centrada si cabe» y conducta sin conexión. | Una sola forma de avisar en todo el sistema. |
| D5 | **Código propio en cada repo** (no un paquete compartido). | Hub y web se publican a ritmos distintos; ~600 líneas no justifican una dependencia de build entre repos. |
| D6 | **`broadcastType()` en todas las notificaciones.** | El `type` en vivo pasa a ser el real. La isla web lo trata igual como señal (relee por HTTP), pero deja de haber dos «types» para el mismo aviso. |
| D7 | **El broadcast de las notificaciones sale síncrono** (`BroadcastMessage::onConnection('sync')`), protegido. | Hallazgo de la revisión: `BroadcastNotificationCreated` es `ShouldBroadcast` (encolado) y **producción no corre colas** (`reverb-websockets.md`). Hoy ningún aviso llega en vivo: el hub lo tapaba con su sondeo y la web al recargar. |
| D8 | **Un recordatorio interrumpe sólo a quien puede actuar**: el asignado o, si no hay, quien creó la tarea. | Son los únicos a quienes `AgendaItemPolicy::complete` deja marcar Hecho / +30 min. Mandarlo a toda la sucursal o empresa daría botones que responden 403, y un «Visto» de cualquiera se lo descartaría al dueño. Las tareas de sucursal/empresa siguen visibles para todos en la pantalla Agenda. |

## 3. Nube: la agenda como fuente

### 3.1 Avisos nuevos

| Notificación | `type` | `level` | Payload (además de `title`, `body`) | Cuándo |
|---|---|---|---|---|
| `AgendaReminderDue` | `agenda.reminder.due` | `action` | `item_id`, `priority`, `remind_at` | El comando de §3.2 encuentra el recordatorio vencido |
| `AgendaItemAssignedNotice` | `agenda.item.assigned` | `important` | `item_id`, `assigned_by` (nombre) | Se asigna una tarea a alguien distinto de quien la crea |

Ambas `via() === ['database', 'broadcast']`, `toBroadcast()` con `->onConnection('sync')` (D7) y `broadcastType()` devolviendo su `type`. Textos:

- Recordatorio: título = el del ítem; cuerpo = «Vencía a las HH:MM» (en `config('app.timezone')`, America/Mexico_City: los tenants no tienen zona propia; si `remind_at` no es de hoy, «Vencía el 23 sep, HH:MM»).
- Asignación: título = «Te asignaron una tarea»; cuerpo = «{assigned_by}: {título del ítem}».

`AgendaItemAssignedNotice` **sustituye** al evento `AgendaItemAssigned` (se borra la clase y su dispatch en `AgendaController`; el canal `agenda.user.{userId}` se retira de `routes/channels.php`). Se envía al **crear** con asignado y al **editar** cuando `assigned_to_user_id` cambia a otra persona; nunca a quien hace el cambio.

### 3.2 `agenda:dispatch-reminders`

- Comando nuevo, en el scheduler **cada minuto** (`bootstrap/app.php`, junto a los existentes), `withoutOverlapping()`.
- Consulta `AgendaItem` **tal cual**: en consola no hay `tenant` enlazado y `TenantScope` no filtra (igual que `CheckDevicesCommand`). **No** usar `withoutGlobalScopes()`: quitaría también `SoftDeletes` y traería ítems borrados.
- Condición: activos (`completed_at` y `cancelled_at` nulos), `remind_at <= now()`, `reminder_seen_at` nulo, **`reminder_notified_at` nulo**.
- Por cada ítem: **primero** fija `reminder_notified_at = now()`, **después** notifica al destinatario (§3.3). Un recordatorio se avisa **como mucho una vez**: si el envío falla, se registra y no se reintenta en el minuto siguiente (con Reverb caído, reintentar cada minuto duplicaría filas). La fila de la base se guarda antes que el broadcast, así que un Reverb caído deja el aviso en la bandeja igualmente.
- Columna nueva `agenda_items.reminder_notified_at` (nullable timestamp). **Migración aditiva.** Rellena `reminder_notified_at = now()` en los ítems con `remind_at` **anterior a 24 h** (no se anuncian recordatorios de hace días al desplegar); los de las últimas 24 h aún sin ver se avisarán en el primer minuto, que es lo que la campana de agenda habría mostrado.

### 3.3 Destinatario (D8)

| Ítem | Recibe |
|---|---|
| `assigned_to_user_id` no nulo | El asignado |
| si no | `user_id` (quien la creó) |

Uno solo, siempre del mismo tenant que el ítem. Vive en `AgendaReminderNotifier`, que también centraliza el envío de `AgendaItemAssignedNotice` y el marcado de §3.4 (como `SaleCancellationNotifier` para las cancelaciones).

### 3.4 Cerrar el aviso cuando el ítem se atiende

Cuando un ítem se **completa, cancela, pospone, marca visto o borra** —desde la isla, la pantalla Agenda o el asistente—, se marcan como leídos los avisos `agenda.reminder.due` sin leer de ese ítem. Así, atenderlo en la pantalla Agenda apaga también la isla.

- `notifications.data` es `text` en PostgreSQL (migración `2026_08_23_212248`): la consulta usa `whereRaw("(data::jsonb->>'item_id')::bigint = ?", [$item->id])` junto a `type = AgendaReminderDue::class`. `where('data->item_id', …)` no sirve sobre `text`.
- **Posponer** y **editar `remind_at`** (en `AgendaController::update()`) limpian `reminder_notified_at` y `reminder_seen_at`, para que el comando vuelva a avisar al nuevo vencimiento.
- **Recurrencia:** el clon que crea `complete()` excluye `reminder_notified_at` y `reminder_seen_at` del `replicate()`.

### 3.4 bis Reglas de fallo

Toda llamada a `notify()` de avisos —las nuevas y las existentes (`SaleCancellationNotifier`, los avisos de equipos)— pasa por un guardia `try/catch` que registra y sigue, como `SaleCancellationNotifier::guard()` hoy. Con D7 el broadcast es síncrono y **puede lanzar**; sin guardia, un Reverb caído convertiría en 500 una acción ya guardada (la misma razón de `SafeBroadcast`). El plan revisa cada punto de envío.

### 3.5 Las notificaciones existentes

`SaleCancellationRequested`, `SaleCancellationResolved`, `DeviceBatteryLow`, `DeviceRegistered`, `DeviceSilent`, `DeviceOutdated`:

- `broadcastType()` devuelve el mismo `type` de su `toArray()`. El nombre del evento no cambia (`broadcastAs` sigue siendo el de Laravel), así que `Echo.notification()` y el hub no se enteran.
- `toBroadcast()` con `->onConnection('sync')` (D7).

## 4. Web: la isla

### 4.1 Igual que el hub

Estados reposo / anunciando / bandeja, niveles (`action` se queda, `important` 6 s con pausa al pasar el puntero, `info` sólo latido), cola con `action` primero y «+N más», primera lectura sin anunciar, el evento como señal (relee `GET /avisos`), sondeo **20 s / 4 s** según el estado de Echo, relectura al volver a mostrarse la pestaña, marcado optimista, pie «Sin conexión · actualizado hace N min», movimiento (morph ≤ 240 ms, latido, pulso, «reducir movimiento»), y **«centrada si cabe»** con acople al grupo derecho cuando choca (`islandPlacement` / `islandRight`).

### 4.2 Lo que cambia respecto al hub

- **Datos:** `fetch` a `notifications.index` / `notifications.read` / `notifications.read-all` (ya existen, fuera del prefijo de tenant, con CSRF), adaptado a la forma `{ ok, data, error }` que espera el motor (`error: 'offline'` si `fetch` lanza). En vivo, `window.Echo.private('App.Models.User.{id}').notification(...)`. El estado del socket, con `trackConnection` de `resources/js/lib/realtimeState.js`, que la web ya usa en `branchChannel.js`.
- **Ciclo de vida:** en la web los layouts son componentes que envuelven cada página, así que **la isla se vuelve a montar en cada navegación de Inertia**. El motor es un singleton que **arranca una vez por id de usuario** (`ensureStarted(user)`: si ya corre para ese id, no hace nada) y **no se detiene al desmontar** la isla: ni `stop()` ni `Echo.leave()`. Así un anuncio en curso sobrevive a la navegación y lo que llegue entre dos páginas no se pierde. Cerrar sesión recarga la página entera y lo reinicia. Los oyentes de ventana y de teclado sí se ponen y quitan con cada montaje.
- **Destinos** (`route()` de Ziggy con el slug del tenant):

| `type` | Destino | Condición |
|---|---|---|
| `sale.cancellation.requested` | `sucursal.cancelaciones.index` | `admin-sucursal` |
| `sale.cancellation.approved` / `rejected` | mesa de trabajo del rol (`caja.…` / `sucursal.…`; nombres exactos al planear) | — |
| `device.*` | panel de equipos del rol | — |
| `agenda.reminder.due`, `agenda.item.assigned` | `agenda.index` | — |
| otro | sin destino («Entendido») | — |

Mesa de trabajo: `caja.workbench` (cajero) / `sucursal.workbench` (admin-sucursal). Equipos: `empresa.devices.index` / `sucursal.devices.index` / `caja.devices.index`. **Sin `tenant_slug`** (el superadmin en `Profile/Edit` con `AuthenticatedLayout`): ningún aviso tiene destino y no se muestran las acciones de agenda.

- **Acciones del recordatorio:** en el anuncio y en la fila, tres botones — **Hecho** (`agenda.complete`), **+30 min** (`agenda.snooze`, `minutes: 30`), **Visto** (`agenda.visto`); por D8 quien recibe el aviso siempre puede usarlos — con `router.patch(..., { preserveScroll: true, preserveState: true })`. Tras cualquiera, la isla recoge el anuncio y relee la bandeja (el backend ya marcó leídos los avisos del ítem, §3.4). Tocar el cuerpo lleva a Agenda.
- **Teléfono** (`< sm`): la isla va acoplada al grupo derecho siempre; anunciando y bandeja ocupan el ancho de la pantalla menos 16 px por lado; la bandeja con alto máximo `min(480px, 100dvh - 5rem)`.
- **Pestaña oculta:** sólo suena. Sin notificación del sistema del navegador (fuera de alcance).

### 4.3 Piezas

- `resources/js/lib/notificationsCore.js` (puro; copia adaptada del hub) y `resources/js/lib/notificationRoutes.js`.
- `resources/js/lib/chime.js`.
- `resources/js/composables/useNotifications.js` — motor `createNotificationCenter(deps)` + singleton, mismas firmas que el hub más `ensureStarted(user)`; deps: `api` (fetch), `listenUser` (Echo), `chime`, `isLive`, `isWindowActive`, y sin `notifier` nativo (se pasa un doble vacío). El `user` que usa `routeFor` se arma con `page.props.auth.role` y `auth.user.id`.
- `resources/js/Components/Notifications/NotificationIsland.vue` y `NotificationRow.vue`.
- Se monta en `EmpresaLayout`, `SucursalLayout`, `CajeroLayout` y `AuthenticatedLayout`, donde hoy están las campanas.
- **Se borran** `NotificationBell.vue`, `Agenda/AgendaBell.vue` y `composables/useUserNotifications.js`. `DeviceAlertStrip` se queda. El endpoint `agenda.notificaciones` se **retira** (su único consumidor era `AgendaBell`) junto con su método `notifications()` y sus casos en `tests/Feature/Agenda/AgendaNotificationsTest.php` (los de posponer, visto, cancelar e historial se conservan); `agenda.alerts` sigue para el widget.

## 5. Qué no hace

- No mete alertas calculadas ni tareas atrasadas en la isla (D2).
- No añade notificación del sistema del navegador.
- No pagina la bandeja (20, como hoy).
- No cambia el hub (§6).

## 6. Hub

Sin cambios obligatorios: lee la misma bandeja, así que los avisos de agenda le llegan solos. `agenda.reminder.due` llega como `action` **sin destino** → muestra «Entendido», que marca leído pero **no** completa ni pospone el ítem. Llevar Hecho / +30 min al hub requiere endpoints de agenda en `/api/v1/hub` y queda como siguiente paso (anotado en `carniceria-hub/docs/avisos.md`).

## 7. Pruebas

**PHP (PHPUnit)**

- `agenda:dispatch-reminders`: avisa una sola vez; no avisa ítems completados, cancelados, vistos, futuros ni borrados (soft delete); vuelve a avisar tras posponer y tras editar `remind_at`; si `notify()` lanza, marca `reminder_notified_at`, registra y sigue con los demás; recorre varios tenants.
- Destinatario: el asignado si lo hay; si no, el creador. Nunca un usuario de otro tenant.
- Completar, cancelar, posponer, marcar visto y borrar marcan leídos los avisos de ese ítem y no tocan avisos de otros ítems (con la consulta `jsonb` sobre PostgreSQL).
- La migración marca como avisados los recordatorios de hace más de 24 h y deja los recientes.
- Asignar (al crear y al reasignar) crea `AgendaItemAssignedNotice` para el asignado y no para quien asigna.
- Cada notificación: `broadcastType()` coincide con `toArray()['type']` y `toBroadcast()` va por `sync`.
- Un broadcast que falla no rompe la petición que lo dispara (cancelación, latido de equipo) y la fila queda guardada.
- `Route::has('agenda.notificaciones')` es falso; `agenda.alerts` sigue respondiendo.

**JS (Vitest, `tests/js`)**

- Núcleo y destinos (como en el hub, más los tipos de agenda).
- Motor: los mismos casos que `carniceria-hub/test/useNotifications.test.js`, adaptados a los deps de la web.

**Navegador (Playwright, como con el hub)**

- 1280 px y 390 px: reposo sin tapar nada, anuncio de recordatorio con Hecho / +30 min / Visto, bandeja, teléfono a ancho completo.

## 8. Documentación al implementar

- `docs/modulos/avisos.md`: la isla web, los dos avisos de agenda, `broadcastType()`, quitar los límites ya resueltos.
- `docs/modulos/agenda.md`: la campana desaparece; los recordatorios son avisos; se retira `agenda.notificaciones`; se quita la limitación de `AgendaItemAssigned`.
- `docs/frontend/` (si documenta layouts/campanas) y `docs/README.md` (estado).
- `CLAUDE.md`: en Real-Time, `AgendaItemAssigned` desaparece; en Persistent notifications, la agenda como fuente y el broadcast síncrono.
- `docs/arquitectura/reverb-websockets.md` y las entradas de `docs/README.md` que mencionan `AgendaItemAssigned`.
- `carniceria-hub/docs/avisos.md`: una línea sobre los avisos de agenda sin destino.
- Este spec: `Estado:` → Implementado.
