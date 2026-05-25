# Agenda v2 — Estados, historial y notificaciones globales — Diseño congelado (MVP)

**Fecha:** 2026-05-24
**Estado:** Aprobado para implementación
**Autor:** colaboración con Claude (auditoría + propuesta)
**Base:** extiende `2026-05-24-agenda-recordatorios-design.md` (Agenda v1 ya implementada).

## Objetivo

Hacer la Agenda **útil para la operación diaria**, no solo un calendario visual.
Resolver los problemas detectados en la auditoría: las tareas completadas
desaparecen sin historial, no existe estado "vencida/cancelada", los
recordatorios no avisan al llegar la hora, y no hay presencia fuera de la
pantalla Agenda. Todo **sin cron** (avisos in-app mientras la app está abierta).

## Problemas que resuelve (de la auditoría)

1. Tarea completada desaparece sin historial visible → **pestaña Completadas**.
2. No hay "vencida" y las atrasadas se mezclan en Hoy → **estado derivado
   Atrasada** + sección propia.
3. Recordatorio no avisa al llegar la hora → **campana global + toast por
   polling** (con la app abierta).
4. Sin presencia fuera de Agenda → **campana en los layouts** con badge.
5. "Cancelar" no existe (solo borrar) → **estado Cancelada con motivo**.
6. Bug: notas sin fecha invisibles → **sección Notas** en Hoy.
7. Calendario muestra completadas sin distinguir → **estilo atenuado**.
8. Evento Reverb sin consumidor → se mantiene fuera del MVP (el aviso va por
   polling; Reverb proactivo queda para la fase con cron).

## Alcance MVP

- **Estados**: Pendiente, Completada, **Cancelada** (nueva, con motivo),
  **Atrasada/Vencida** (derivada). *Pospuesta* = mover el recordatorio (no es
  estado almacenado). *En proceso* queda **fuera**.
- **Historial**: pestaña **Completadas** (incluye canceladas con filtro).
- **Sección Atrasadas** en Hoy (rojo, arriba) + **sección Notas** (notas sin
  fecha).
- **Campana global** en los 4 layouts (empresa/sucursal/caja) con badge,
  dropdown y acciones rápidas; **toast** cuando un recordatorio vence con la app
  abierta (polling ~60s).
- **Acciones**: completar, **cancelar** (con motivo), **posponer**, marcar
  recordatorio como visto — disponibles desde Hoy, el detalle y la campana.
- Calendario: oculta canceladas, atenúa completadas.

## Fuera de alcance (MVP)

- Cron / push con la app **cerrada** (WhatsApp/email/web-push) → fase futura
  (ahí se enciende el Scheduler de Laravel Cloud).
- Vincular ítems a compra/pago/proveedor/cliente y mostrarlos dentro de esos
  módulos.
- Estado "En proceso", RRULE avanzado, feed de suscripción de calendario.
- Consumir el evento Reverb `AgendaItemAssigned` en el front (se difiere).

## Cambios de datos

Migración que **agrega** a `agenda_items` (todo nullable, aditivo):

| Columna | Tipo | Uso |
| --- | --- | --- |
| `cancelled_at` | timestamp nullable | marca cancelada (≠ soft delete) |
| `cancel_reason` | string(255) nullable | motivo de cancelación |
| `reminder_seen_at` | timestamp nullable | el usuario ya vio el aviso del recordatorio (evita repetir el toast) |

Índice extra: `(tenant_id, completed_at)` y `(tenant_id, cancelled_at)` para
listados de historial/activos.

## Estados (derivación)

- **Pendiente**: `completed_at` null AND `cancelled_at` null AND
  (`starts_at` null OR `starts_at >= ahora`).
- **Atrasada (vencida)**: `completed_at` null AND `cancelled_at` null AND
  `starts_at < ahora`. *(Derivado — NO se guarda. Usa datetime: la hora ahora SÍ
  cuenta, a diferencia de v1 que usaba `whereDate`.)*
- **Completada**: `completed_at` no null.
- **Cancelada**: `cancelled_at` no null.

Helper en el modelo: scopes `pending()`, `overdue()`, `active()` (no completada
ni cancelada), `history()` (completada o cancelada). Accessor `state` (string)
para el front.

## Endpoints (se agregan al grupo `{tenant}/agenda`)

```
PATCH  agenda/{item}/cancelar        cancel (motivo) — policy "cancel"
PATCH  agenda/{item}/posponer        snooze: remind_at = ahora + minutes; limpia reminder_seen_at
PATCH  agenda/{item}/visto           markReminderSeen: reminder_seen_at = ahora
GET    agenda/completadas            historial (JSON, paginado): completadas + canceladas
GET    agenda/notificaciones         JSON para la campana (due reminders + atrasadas + alertas + counts)
```
Ya existen: index, calendar, alerts, store, update, complete, destroy, ics.

### `GET agenda/notificaciones` (payload)

```json
{
  "due_reminders": [ { id, title, type, starts_at, remind_at } ],
  "overdue":       [ { id, title, type, starts_at } ],
  "alerts":        [ ...AgendaAlertService... ],
  "counts": { "due_reminders": n, "overdue": n, "alerts": n, "total": n }
}
```
- `due_reminders`: `remind_at <= ahora` AND `reminder_seen_at` null AND activa,
  `visibleTo(user)`. (Disparan el toast.)
- `overdue`: `starts_at < ahora` AND activa, `visibleTo(user)` (máx 10 + count).
- `alerts`: `AgendaAlertService::for($user)` (financieras, ya existe).
- `total` = due_reminders + overdue + alerts (para el badge).

## Cambios en queries existentes (`index`)

- **Excluir canceladas** en Hoy, Próximos y Calendario.
- **Atrasadas**: separar en su propio arreglo `overdue` (pendientes con
  `starts_at < ahora`).
- **Hoy** (estricto): activas con `starts_at` HOY (o `remind_at` hoy) que no
  estén atrasadas.
- **Notas sin fecha**: arreglo `notes` = `type=note`, activa, `starts_at` null
  (hoy quedaban invisibles).
- `index` devuelve: `overdue`, `today`, `upcoming`, `notes`, `alerts`.

## Calendario

- La consulta de expansión **excluye canceladas**; incluye completadas pero el
  payload agrega `completed_at` para que el front las pinte atenuadas/tachadas.

## Notificaciones in-app (sin cron)

**Componente `AgendaBell.vue`** montado en `EmpresaLayout`, `SucursalLayout`,
`CajeroLayout` y `AuthenticatedLayout` (que usa el dashboard de caja):

- En `onMounted` y cada **60s** hace `fetch(agenda.notificaciones)`.
- **Badge** = `counts.total`; ícono campana en la barra superior.
- **Dropdown** lista due reminders + atrasadas + alertas, con acciones:
  **Completar**, **Posponer** (+15/+30/+60 min, "mañana 9am"), **Ver** (link a
  Agenda). Al abrir el dropdown se marca `visto` los due reminders mostrados.
- **Toast**: si aparece un `due_reminder` cuyo `id` no se había mostrado en esta
  sesión, muestra un toast discreto (reusa el estilo de `FlashToast`). El toast
  permite Completar o Posponer; al descartarlo se llama `visto`.
- **Degradación**: si el fetch falla, la campana queda en 0 y no rompe la página.

**Límite explícito**: con la app **cerrada** no hay aviso (el polling es del
navegador). El push real con la app cerrada es la fase con cron.

## Permisos

- Nueva ability `cancel` en `AgendaItemPolicy` = misma regla que `update`
  (creador, o admin del alcance: admin-sucursal sobre su sucursal, admin-empresa
  sobre todo). Cajero cancela lo suyo.
- `posponer` y `visto`: permitido a quien puede `complete` (creador o asignado).
- `completadas` y `notificaciones`: cualquier usuario autenticado del grupo
  (la query ya está scopeada por `visibleTo`).

## Flujos

- **Completar**: check en Hoy / dropdown campana / detalle → `completed_at=ahora`
  → sale de activas, aparece en **Completadas** (+ recurrencia genera la
  siguiente, ya implementado).
- **Cancelar**: acción con motivo (en detalle o dropdown) → `cancelled_at=ahora`
  + `cancel_reason` → aparece en **Completadas** (filtro Canceladas). No se
  borra.
- **Posponer**: botón con presets → `remind_at = ahora + n`; `reminder_seen_at`
  = null (para que vuelva a avisar). No cambia `starts_at`.
- **Marcar visto**: al ver el aviso en la campana/toast → `reminder_seen_at=ahora`
  (no vuelve a saltar, pero sigue en Hoy/atrasadas hasta completarse).

## UI

- **Hoy**: secciones en orden → *Atrasadas* (rojo) · *Hoy* · *Próximos* ·
  *Notas*. Cada ítem: completar, editar, posponer, cancelar, .ics.
- **Calendario**: completadas atenuadas/tachadas; canceladas no aparecen.
- **Alertas**: igual que hoy (financieras).
- **Completadas** (pestaña nueva): historial paginado; chips Completada/Cancelada;
  filtro para ver solo canceladas. Cargado por `fetch(agenda.completadas)`.
- **Campana**: en todos los layouts; badge + dropdown + toast.

## Caso de uso clave (tarea hoy 2pm → llega la hora)

1. Con la app abierta (cualquier página), la campana detecta `remind_at<=ahora`
   no visto → **toast** con Completar/Posponer + badge sube.
2. Se marca `visto` al interactuar; no se repite.
3. Si pasa `starts_at` y sigue activa → **Atrasada** (rojo en Hoy + en la
   campana).
4. Si se completa → **Completadas**.
5. App cerrada a esa hora → el aviso aparece al volver a abrir (límite del MVP).

## Tests (PHPUnit, feature)

- `cancel`: setea `cancelled_at`+`cancel_reason`; queda fuera de activas; sale
  en `completadas`; policy (ajeno prohibido).
- Derivación **Atrasada**: tarea pendiente con `starts_at` pasado entra en
  `overdue` de `index` y de `notificaciones`; completada/cancelada no.
- `notificaciones`: due reminders solo con `remind_at<=ahora` y
  `reminder_seen_at` null y activa; respeta `visibleTo` (aislamiento por
  sucursal/tenant); `counts.total` correcto.
- `visto`: setea `reminder_seen_at`; ya no aparece en due_reminders.
- `posponer`: mueve `remind_at`, limpia `reminder_seen_at`, no cambia
  `starts_at`.
- `completadas`: lista completadas y canceladas, scopeado.
- **Notas sin fecha**: aparecen en `index.notes`.
- Calendario: excluye canceladas.

## Roadmap posterior

- Cron (`schedule:run` en Laravel Cloud) + queue + canal (WhatsApp API / email /
  web-push) para avisar con la app cerrada y resúmenes diarios.
- Vincular ítems a entidades (compra/pago/proveedor/cliente) y mostrarlos en
  esos módulos.
- Estado "En proceso", RRULE, feed de suscripción, consumo de Reverb proactivo.
