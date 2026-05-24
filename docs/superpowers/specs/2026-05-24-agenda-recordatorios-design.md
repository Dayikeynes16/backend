# Agenda y recordatorios — Diseño congelado (v1)

**Fecha:** 2026-05-24
**Estado:** Aprobado para implementación
**Autor:** colaboración con Claude (brainstorming + propuesta)

## Objetivo

Dar a la aplicación una **agenda interna** de elementos ligados al tiempo:
tareas, eventos y notas que los usuarios crean por empresa, sucursal o de
forma personal, más **alertas automáticas** derivadas de los datos que ya
existen (cuentas por pagar, fiados vencidos, turno sin cerrar). Todo se ve en
una sección **"Agenda"** con vistas *Hoy*, *Calendario* y *Alertas*, con
recordatorios in-app, envío manual por WhatsApp y exportación de eventos al
calendario del dispositivo (iOS/Android) vía `.ics`.

## Decisión de fondo: dos fuentes que conviven, no se mezclan

El sistema maneja **dos naturalezas distintas** que se presentan juntas pero
se almacenan diferente:

1. **Ítems manuales** — los crea una persona (tarea/evento/nota). Son CRUD
   normal sobre la tabla `agenda_items`.
2. **Alertas automáticas** — se **calculan** leyendo datos existentes
   (compras con saldo, fiados, turnos). **No se guardan** como filas nuevas;
   duplicarlas crearía una segunda fuente de verdad. Son solo lectura.

Ambas comparten un "shape" común para que el frontend las pinte igual, pero el
backend las trata por separado.

## Alcance v1

- Tabla única `agenda_items` con tipos **task · event · note**.
- Alcance por ítem: **company · branch · personal**, con visibilidad por rol
  para los 3 roles (admin-empresa, admin-sucursal, cajero).
- **Asignación** de tareas a un usuario.
- **Recurrencia simple**: `none · daily · weekly · monthly`.
- **Alertas automáticas derivadas** (solo lectura): cuentas por pagar, fiados
  vencidos, turno sin cerrar, API key por expirar.
- Vistas **Hoy · Calendario · Alertas** + widget "Hoy" en dashboards.
- Recordatorios **in-app** (al cargar + en vivo por Reverb) y botón
  **WhatsApp manual** (`wa.me`).
- Botón **"Agregar al calendario" (`.ics`)** por evento, con alarma (VALARM).
- Zona horaria única: **`America/Mexico_City`**.

## Fuera de alcance (v1)

- Feed de suscripción de calendario (`webcal`/`.ics` por usuario) — v1.1.
- Sync bidireccional con Google Calendar (OAuth/API) — futuro.
- Recurrencia avanzada (RRULE, "cada 2 semanas", "último viernes").
- Push automático fuera de la app (cron + WhatsApp API + email).
- Auto-creación de tareas desde acciones del sistema (p. ej. crear compra a
  crédito → tarea de pago). Las cuentas por pagar viven como **alerta
  derivada**, no como tarea.
- Anclar notas a una entidad (cliente/proveedor/compra), comentarios,
  adjuntos.
- Alertas de inventario/caducidad (requiere datos que hoy no existen).

## Modelo de datos

Tabla `agenda_items` (usa `BelongsToTenant`, `SoftDeletes`).

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | bigint | |
| `tenant_id` | FK | auto por `TenantScope` |
| `type` | string | `task` \| `event` \| `note` (enum `AgendaItemType`) |
| `title` | string(160) | requerido |
| `body` | text nullable | texto largo (notas/descr.) |
| `scope` | string | `company` \| `branch` \| `personal` (enum `AgendaScope`) |
| `branch_id` | FK nullable | requerido si `scope=branch`; null si `company`/`personal` |
| `user_id` | FK | creador |
| `assigned_to_user_id` | FK nullable | solo tareas |
| `starts_at` | datetime nullable | evento: inicio · tarea: vencimiento · nota: fecha opcional |
| `ends_at` | datetime nullable | evento: fin |
| `all_day` | boolean | default `false` |
| `remind_at` | datetime nullable | cuándo "salta" el recordatorio in-app |
| `completed_at` | datetime nullable | fuente de verdad de "hecha" (solo tareas; null = pendiente) |
| `priority` | string nullable | `low` \| `normal` \| `high` (tareas) |
| `recurrence` | string | `none`\|`daily`\|`weekly`\|`monthly` (enum `AgendaRecurrence`), default `none` |
| `recurrence_until` | date nullable | corta la serie (opcional) |
| `created_at`/`updated_at`/`deleted_at` | timestamps | |

### Campos por tipo

- **task**: `title`, `body?`, `scope`, `assigned_to_user_id?`, `starts_at?`
  (vencimiento), `remind_at?`, `priority?`, `recurrence`, `completed_at`.
- **event**: `title`, `body?`, `scope`, `starts_at` (requerido), `ends_at?`,
  `all_day`, `remind_at?`, `recurrence`.
- **note**: `title`, `body?`, `scope`, `starts_at?` (puede ir **sin fecha**).

`completed_at`, `priority` y `assigned_to_user_id` se ignoran para
`event`/`note`. `ends_at`/`all_day` se ignoran para `task`/`note`.

### Índices

- `(tenant_id, scope, branch_id)` — listados por alcance.
- `(tenant_id, starts_at)` — rango del calendario.
- `(tenant_id, type, completed_at)` — pendientes/Hoy.
- `(assigned_to_user_id)` — "lo asignado a mí".

## Enums (convención del proyecto, TitleCase)

- `AgendaItemType`: `Task`, `Event`, `Note`.
- `AgendaScope`: `Company`, `Branch`, `Personal`.
- `AgendaRecurrence`: `None`, `Daily`, `Weekly`, `Monthly`.
- `AgendaPriority`: `Low`, `Normal`, `High`.

## Visibilidad (quién ve qué)

`AgendaItem::query()` se acota según el usuario autenticado:

- Ítems `company` del tenant → todos.
- Ítems `branch` con `branch_id` = sucursal del usuario.
- Ítems `personal` con `user_id` = el usuario.
- Tareas con `assigned_to_user_id` = el usuario (aunque sean de otra sucursal).

Por rol:

- **admin-empresa**: ve **todo** el tenant (todas las sucursales).
- **admin-sucursal**: `company` + su sucursal + sus personales + asignadas.
- **cajero**: `company` + su sucursal + sus personales + asignadas.

Quién puede **crear** cada alcance:

- `personal`: cualquier rol.
- `branch`: admin-empresa (cualquier sucursal) y admin-sucursal/cajero (solo su
  sucursal).
- `company`: solo admin-empresa.

Estas reglas se encapsulan en `AgendaItemPolicy` (`view`, `update`, `delete`,
`complete`) y en un scope de consulta `visibleTo(User $user)`.

## Alertas automáticas (`AgendaAlertService`)

Servicio sin estado que recibe el usuario y devuelve una lista de DTOs
normalizados. **No escribe nada.** Cada alerta:

```
{ key, source, title, detail, amount?, due_at?, severity, action_url, whatsapp_url? }
```

Fuentes v1 (todas acotadas a las sucursales visibles para el usuario):

- **Cuentas por pagar** — `Purchase` con `amount_pending > 0`; `detail` con
  proveedor y antigüedad; `action_url` a la compra; severidad por antigüedad.
- **Fiados vencidos** — el saldo por cobrar **no es una columna por cliente**:
  se agrega de `sales.amount_pending` y se envejece por `completed_at`/
  `created_at`. Se reusa el servicio existente `CollectionMetrics`
  (`aging()` / `receivablesTable()`) para obtener los clientes con saldo
  vencido sobre umbral; `whatsapp_url` con el mensaje de cobro (mismo patrón
  que ya existe en clientes).
- **Turno sin cerrar** — `CashRegisterShift` abierto con `opened_at` de un día
  anterior (o > N horas).
- **API key por expirar** — `ApiKey` con `expires_at` dentro de N días.

Reusa servicios/consultas existentes (cobranza, compras) en vez de
reimplementar. Los umbrales (`N días`, antigüedad de fiado) son constantes
del servicio en v1.

## Recurrencia simple

- **Eventos recurrentes**: NO se materializan filas. Un
  `AgendaCalendarService` recibe un rango `[from, to]` y **expande en memoria**
  las ocurrencias de cada evento recurrente que caigan en el rango (respetando
  `recurrence_until`). El calendario y "Hoy" consumen ese servicio.
- **Tareas recurrentes**: se mantiene **una sola fila viva** por serie. Al
  marcar `complete`, si `recurrence != none` y no se pasó `recurrence_until`,
  se **clona** la tarea con el siguiente `starts_at`/`remind_at` (pendiente) y
  la actual queda completada. Así no llevamos estado por-ocurrencia.
- Sin recurrencia, completar solo setea `completed_at`.

## Recordatorios y notificación (sin cron)

- **In-app al cargar**: la vista *Hoy* y el widget consultan ítems con
  `starts_at`/`remind_at` ≤ ahora y no completados (+ alertas derivadas).
- **Bloque "Próximos"**: la vista *Hoy* incluye además los ítems futuros
  cercanos (mañana / esta semana) aunque no tengan `remind_at`, para que al
  abrir la app se vean las entregas/eventos que vienen sin depender de haber
  configurado un recordatorio. (Compensa la ausencia de push por tiempo en v1.)
- **En vivo (Reverb)**: al crear/asignar un ítem dirigido a un usuario o
  sucursal, se emite un evento `ShouldBroadcast` en un canal privado
  (`agenda.user.{userId}` y/o reusar `sucursal.{branchId}`) → toast. No hay
  disparo por tiempo (eso requeriría cron, fuera de v1).
- **WhatsApp manual**: botón `wa.me` en alertas de cobranza/pago y en tareas
  con teléfono asociado, reutilizando `WhatsappMessageService`.

## Exportación al calendario del dispositivo (`.ics`)

- Endpoint que genera un archivo iCalendar para un ítem (principalmente
  eventos; tareas con vencimiento como `VEVENT` de día completo).
- Incluye `VALARM` (`TRIGGER` relativo a `remind_at`/`starts_at`) para que el
  calendario del dispositivo dispare el aviso.
- `UID` estable por ítem (`agenda-{id}@{tenant-slug}`) para que reimportar
  actualice en vez de duplicar.
- Fechas en UTC con `TZID America/Mexico_City`.
- Funciona en iOS (Apple Calendar) y Android (Google Calendar) al abrir el
  archivo; sin cuentas ni configuración.

## Rutas

Como el modelo y la lógica son idénticos entre roles (solo cambia la
visibilidad, ya resuelta por policy/scope), se usa **un solo grupo** bajo
`/{tenant}/agenda`, accesible a los 3 roles (middleware
`role:admin-empresa|admin-sucursal|cajero|superadmin`, siguiendo la convención
del resto de grupos que siempre añaden `superadmin`), con un `AgendaController`
compartido. Esto evita triplicar controladores. Es una **desviación** del
patrón actual (un grupo prefijado por rol: `empresa`/`sucursal`/`caja`); el
controlador resuelve sucursal/rol desde el usuario autenticado y la policy, no
desde el prefijo de ruta.

```
GET    /{tenant}/agenda                 index (Hoy + datos base)
GET    /{tenant}/agenda/calendario      rango del calendario (?from=&to=) → ítems + ocurrencias
GET    /{tenant}/agenda/alertas         alertas derivadas (AgendaAlertService)
POST   /{tenant}/agenda                 crear
PUT    /{tenant}/agenda/{item}          editar
PATCH  /{tenant}/agenda/{item}/completar  completar tarea (+ regen recurrencia)
DELETE /{tenant}/agenda/{item}          eliminar (soft delete)
GET    /{tenant}/agenda/{item}/ics      descargar .ics
```

Frontend Inertia: `Agenda/Index.vue` con pestañas *Hoy · Calendario ·
Alertas*; widget reutilizable en los dashboards.

## Permisos

- `AgendaItemPolicy`:
  - `view`: el ítem pasa por `visibleTo`.
  - `update`/`delete`: creador, o admin del alcance correspondiente
    (admin-sucursal sobre ítems de su sucursal, admin-empresa sobre todo).
  - `complete`: creador o asignado.
- Crear con `scope=company` requiere `admin-empresa`; `scope=branch` requiere
  pertenecer a esa sucursal (o admin-empresa).

## Validaciones

- `title` requerido, ≤ 160.
- `type` y `scope` en su enum; `scope` permitido para el rol.
- `branch_id` requerido si `scope=branch`, debe pertenecer al tenant y ser
  visible para el usuario.
- `assigned_to_user_id` (si viene) debe ser del tenant y de la sucursal del
  ítem (o del tenant si `company`).
- `event`: `starts_at` requerido; `ends_at` ≥ `starts_at` si viene.
- `remind_at` ≤ `starts_at` cuando ambos existen.
- `recurrence` en enum; `recurrence_until` ≥ fecha base si viene.

## Zona horaria

Se persiste en UTC (default Laravel) y se presenta/edita en
`America/Mexico_City`. El `.ics` declara `TZID` para que el dispositivo muestre
la hora correcta. v1 asume **una sola zona** para todos los tenants.

## Casos borde y riesgos

- **No duplicar datos**: las alertas se calculan, nunca se escriben como
  ítems. La acción siempre enlaza al módulo real.
- **Aislamiento + visibilidad**: el punto más delicado; se cubre con
  `visibleTo` + `AgendaItemPolicy` + pruebas por rol.
- **Tarea recurrente**: al completar genera la siguiente; si
  `recurrence_until` ya pasó, no genera. Borrar una tarea recurrente borra solo
  la fila viva (no hay serie materializada).
- **Evento recurrente sin fin**: se acota la expansión al rango visible del
  calendario; nunca se expande "infinito".
- **Ruido**: la vista *Hoy* prioriza lo accionable (vence hoy/vencido +
  alertas), no todo el historial.
- **Reverb**: si el worker/WS no está disponible, el aviso in-app al cargar
  sigue funcionando (degradación elegante).
- **Crecimiento**: ítems completados viejos se conservan (soft delete manual);
  archivado automático queda para después.

## Tests (PHPUnit, feature)

- CRUD de cada tipo (task/event/note) con `BelongsToTenant`.
- Visibilidad por rol: admin-empresa ve todo; admin-sucursal y cajero solo
  company + su sucursal + personales + asignadas; aislamiento entre sucursales
  y entre tenants.
- Policy: crear `company` solo admin-empresa; `branch` fuera de la sucursal
  prohibido; editar/borrar ajeno prohibido.
- Completar tarea: setea `completed_at`; con recurrencia genera la siguiente y
  respeta `recurrence_until`.
- Expansión de eventos recurrentes en un rango (conteo correcto de
  ocurrencias).
- `AgendaAlertService`: arma alertas de cuentas por pagar y fiados acotadas a
  las sucursales visibles; no escribe en BD.
- `.ics`: genera contenido válido con `VEVENT`, `VALARM` y `UID` estable.

## Roadmap posterior

- **v1.1** — Feed de suscripción de calendario por usuario (URL tokenizada
  `.ics`): se suscribe una vez y todo aparece/actualiza solo (one-way).
- **v2** — Push automático: `schedule:run` (cron) + jobs en queue para disparar
  recordatorios fuera de la app (in-app proactivo / email / WhatsApp API).
- Sync bidireccional con Google Calendar (OAuth).
- Recurrencia avanzada (RRULE), excepciones por ocurrencia.
- Auto-creación de tareas desde acciones del sistema (compra a crédito →
  recordatorio de pago), configurable.
- Anclar notas/tareas a entidades (cliente, proveedor, compra) con enlace
  bidireccional.
- Alertas de inventario/caducidad (cuando exista tracking de stock).
