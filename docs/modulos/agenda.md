# Agenda

Agenda interna del tenant: tareas, eventos y notas ligados al tiempo, con alcance por empresa/sucursal/personal, recurrencia simple, recordatorios in-app, **alertas automáticas derivadas** de datos existentes (cuentas por pagar, fiados vencidos, turnos sin cerrar, API keys por expirar), dictado con IA (texto + voz) y exportación de eventos a `.ics`. Es el único módulo cuyo grupo de rutas comparten **los 4 roles** bajo `/{tenant}/agenda`.

## Responsabilidades

- CRUD de ítems manuales (`agenda_items`): tarea (`task`), evento (`event`) y nota (`note`).
- Visibilidad por alcance (`company` / `branch` / `personal`) + asignación de tareas a un usuario del tenant.
- Estados: pendiente, completada, **cancelada con motivo** y **atrasada** (derivada, no almacenada), con historial (pestaña Completadas).
- Recurrencia simple (`none · daily · weekly · monthly`) con expansión en memoria para el calendario y regeneración al completar.
- Recordatorios (`remind_at`) con acciones: posponer (snooze), marcar visto, completar, cancelar — desde la pantalla Agenda y desde la **isla de avisos** del encabezado, donde un recordatorio vencido llega como aviso ([avisos](avisos.md)).
- Calcular **alertas automáticas** de solo lectura (nunca escriben en BD) y presentarlas junto a los ítems manuales con un shape común.
- Prerellenar el formulario con una propuesta de IA a partir de dictado ("Recuérdame entregar carne a las 2pm mañana").
- Exportar un ítem como archivo `.ics` (con `VALARM`) para agregarlo al calendario del dispositivo.

**No hace** (fuera de alcance según specs v1/v2):

- Push con la app cerrada (WhatsApp API / email / web-push). El aviso de un recordatorio sí se genera sin nadie conectado (comando por minuto) y queda en la bandeja, pero sólo se ve al abrir la app.
- Sync con Google Calendar ni feed de suscripción (`webcal`).
- Recurrencia avanzada (RRULE, "cada 2 semanas").
- Auto-crear tareas desde acciones del sistema (las cuentas por pagar viven como alerta derivada, no como tarea).
- Anclar ítems a entidades (cliente/proveedor/compra), comentarios, adjuntos.
- Estado "En proceso".

## Decisiones

| Decisión | Razón |
|---|---|
| **Dos fuentes que conviven, no se mezclan**: ítems manuales (CRUD en `agenda_items`) vs alertas derivadas (calculadas al vuelo, solo lectura) | Materializar las alertas crearía una segunda fuente de verdad. `AgendaAlertService` NO escribe en BD (test lo verifica). |
| Grupo de rutas único compartido por los 4 roles (`role:admin-empresa\|admin-sucursal\|cajero\|superadmin`) | La agenda es transversal; la separación se hace por **scope + policy**, no por prefijo de rol. La página resuelve el layout según el rol. |
| Estado `state` **derivado** (accessor + `$appends`), no columna | `completed` / `cancelled` / `overdue` / `pending` se calculan de `completed_at`, `cancelled_at` y `starts_at` pasado. Sin jobs que marquen "vencida". |
| Cancelar ≠ borrar | `cancelled_at` + `cancel_reason` (opcional, max 255) conservan historial; el soft delete queda para eliminación real. |
| Recurrencia **materializada al completar, expandida sólo hacia adelante** | El calendario expande ocurrencias en memoria (`AgendaCalendarService`, guard de 1000 iteraciones), pero **sólo las de ítems vivos**: una fila con `completed_at` aparece únicamente en su fecha. Al **completar** un ítem recurrente se clona la siguiente ocurrencia viva (respetando `recurrence_until` y desplazando `remind_at` con el mismo offset), así que cada fila representa UNA ocurrencia concreta. Antes se expandían también las completadas y la misma tarea salía tachada en los 42 días del mes, pasados y futuros (corregido 2026-08-06). |
| Posponer = mover `remind_at`, no un estado | `snooze` suma minutos (1 – 10080 = 7 días) y limpia `reminder_seen_at` y `reminder_notified_at` (se volverá a avisar a la nueva hora). |
| **Recordatorios y asignaciones son avisos** (desde 2026-09-24), sin campana propia | La agenda es una fuente más de [avisos](avisos.md): el comando `agenda:dispatch-reminders` (cada minuto) crea un `AgendaReminderDue` al vencer y asignar crea un `AgendaItemAssignedNotice`. Llegan en vivo por la isla y no se pierden si no estabas. Sustituye a la campana `AgendaBell` y a su endpoint `agenda.notificaciones`, que recalculaba alertas financieras en cada carga (el polling de 60 s ya se había retirado el 2026-08-21 por caro). |
| Alertas derivadas y atrasadas **no** son avisos | Son estado, no eventos: guardarlas como fila duplicaría una verdad que se calcula al momento. Se ven en la pestaña Alertas y en el widget de los dashboards. |
| Un recordatorio avisa **sólo a quien puede actuar**: el asignado o, si no hay, el creador | Son los únicos a quienes `AgendaItemPolicy::complete` deja completar/posponer. Las tareas de sucursal/empresa siguen visibles para todos en la pantalla. |
| Captura IA **stateless** (sin tabla de drafts) | A diferencia de Gastos/Compras, la agenda no tiene adjuntos: el endpoint devuelve la propuesta directo y la "confirmación" es el `store` normal del modal. No persiste nada. |
| La IA **nunca asigna** la tarea a una persona | Decisión de diseño: aunque el dictado mencione un nombre, la asignación es manual en el modal (el parser jamás incluye `assigned_to_user_id`). |
| Zona horaria única `America/Mexico_City` | El prompt de IA recibe "HOY" en esa zona para resolver fechas relativas; el frontend formatea con esa TZ. |

## Modelo de datos

Tabla única `agenda_items` (usa `BelongsToTenant` + `SoftDeletes` + `HasFactory`).

```
agenda_items
├─ tenant_id (FK, cascade)        ← auto por TenantScope
├─ type          string           ← task | event | note        (enum AgendaItemType)
├─ title (160), body (text?)
├─ scope         string           ← company | branch | personal (enum AgendaScope)
├─ branch_id     FK? (nullOnDelete)
├─ user_id       FK users         ← creador (cascade)
├─ assigned_to_user_id FK? users  ← asignado (nullOnDelete)
├─ starts_at?, ends_at?, all_day (bool, default false)
├─ remind_at?, reminder_seen_at?  ← recordatorio y "visto"
├─ reminder_notified_at?          ← cuándo se avisó en la isla (un aviso por vencimiento)
├─ completed_at?, cancelled_at?, cancel_reason? (255)
├─ priority?     string           ← low | normal | high        (enum AgendaPriority)
├─ recurrence    string def none  ← none | daily | weekly | monthly (enum AgendaRecurrence)
├─ recurrence_until? (date)
└─ timestamps + softDeletes
```

Índices: `(tenant_id, scope, branch_id)`, `(tenant_id, starts_at)`, `(tenant_id, type, completed_at)`, `(tenant_id, completed_at)`, `(tenant_id, cancelled_at)`, `assigned_to_user_id`. Además, índice parcial `agenda_items_pending_reminders_idx` sobre `remind_at` limitado a recordatorios vivos sin avisar ni ver (lo único que busca el comando por minuto). Migraciones: `2026_05_24_120000_create_agenda_items_table` (v1) + `2026_05_24_130000_add_states_to_agenda_items` (v2: cancelación + visto) + `2026_09_24_000000_add_reminder_notified_at_to_agenda_items` (avisos: columna, índice y backfill que da por avisados los recordatorios de hace más de 24 h).

### Scopes del modelo (`App\Models\AgendaItem`)

| Scope | Qué filtra |
|---|---|
| `visibleTo(User)` | `company` del tenant + `branch` de su sucursal (admin-empresa/superadmin ven todas) + `personal` propios + asignadas a él. **Todas las queries del controller pasan por aquí.** |
| `active()` | sin `completed_at` ni `cancelled_at` |
| `overdue()` | activo con `starts_at` en el pasado |
| `pending()` | activo sin fecha o con fecha futura |
| `history()` | completadas o canceladas |

`AgendaRecurrence::advance(Carbon)` avanza a la siguiente ocurrencia (`addDay`/`addWeek`/`addMonthNoOverflow`).

## Roles y permisos

Autorización vía `AgendaItemPolicy` + ability sin modelo `createScope` (en `StoreAgendaItemRequest::authorize`).

| Acción | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| Ver ítem `company` | ✅ | ✅ | ✅ | ✅ |
| Ver ítem `branch` | ✅ todas | ✅ todas | solo su sucursal | solo su sucursal |
| Ver ítem `personal` | solo propio o asignado | solo propio o asignado | ídem | ídem |
| Crear con scope `company` | ✅ | ✅ | ❌ (403) | ❌ (403) |
| Crear con scope `branch` | ✅ elige sucursal | ✅ elige sucursal | forzado a la suya | forzado a la suya |
| Crear `personal` | ✅ | ✅ | ✅ | ✅ |
| Editar / eliminar / cancelar | ✅ | ✅ | creador, o ítems `branch` de su sucursal | solo lo que creó |
| Completar / posponer | creador **o asignado** | ídem | ídem | ídem |
| Dictar con IA | ✅ | ✅ | ✅ | ✅ |

Detalles del policy:

- `view`: cross-tenant siempre `false`; `company` visible a todos; asignado siempre ve; `personal` solo el creador; `branch` requiere misma sucursal (o ser admin de empresa).
- `update`/`delete`/`cancel`: creador, admin de empresa, o admin-sucursal sobre ítems `branch` de su sucursal.
- `complete` (y `snooze`, que reusa `complete`): **solo creador o asignado** — un admin no puede "completar por otro".
- Para no-admins, el controller **fuerza `branch_id = $user->branch_id`** cuando el scope es `branch` (en store y update), y `branch_id` solo es requerido en el request para admin-empresa/superadmin.

## Flujos

### Ciclo de vida (estado derivado)

```
pending ──completar──▶ completed        (si hay recurrencia: se clona la siguiente ocurrencia)
   │ └──(starts_at pasa sin completar)──▶ overdue   ← derivado, no almacenado
   └──cancelar (motivo opcional)──▶ cancelled       ← conserva historial
```

### Recordatorios (avisos de la isla)

1. Al crear/editar se fija `remind_at`.
2. Cada minuto, `agenda:dispatch-reminders` busca ítems activos con `remind_at <= now`, `reminder_seen_at` y `reminder_notified_at` nulos. Por cada uno, `AgendaReminderNotifier::remind()` fija `reminder_notified_at` y **después** notifica `AgendaReminderDue` (`type agenda.reminder.due`, nivel `action`) al asignado o, si no hay, al creador. Se avisa como mucho una vez: si el envío falla, se registra y no se reintenta.
3. El aviso llega en vivo a la isla con tres acciones: **Hecho** (`PATCH {item}/completar`), **+30 min** (`PATCH {item}/posponer` con `minutes: 30`) y **Visto** (`PATCH {item}/visto`). Tocar el cuerpo lleva a la Agenda.
4. **Atender el ítem cierra el aviso**, se haga desde la isla o desde la pantalla: completar, cancelar, posponer, marcar visto, borrar y reasignar marcan leídos sus avisos pendientes (`AgendaReminderNotifier::close()`).
5. **Rearmar:** posponer, cambiar `remind_at` (comparado al minuto) o cambiar el asignado limpian `reminder_notified_at` y `reminder_seen_at`, y el comando vuelve a avisar al nuevo vencimiento o al nuevo asignado. Un `PUT` que no cambia ninguno de los dos no toca nada. El clon de una tarea recurrente nace sin ambas marcas.

Detalle del mecanismo (destinatario, broadcast síncrono, índice): [avisos](avisos.md#la-agenda-como-fuente).

### Alertas automáticas (`AgendaAlertService::for(User)`)

Solo lectura, acotadas a la(s) sucursal(es) visibles del usuario (admin-empresa/superadmin: todas):

| Fuente (`source`) | Regla | Severidad `high` |
|---|---|---|
| `accounts_payable` | compras con `amount_pending > 0` (máx 50) | compra con +30 días |
| `overdue_credit` | fiados con `age_days > 30` (vía `CollectionMetrics::receivablesTable`) | +60 días; incluye `phone` para botón WhatsApp (`wa.me`) |
| `open_shift` | turnos abiertos desde antes de hoy | siempre |
| `api_key` | API keys activas que expiran en ≤ 7 días | — |

Shape común: `{ key, source, title, detail, amount, due_at, severity }`.

### Asignación

Al crear un ítem asignado a otra persona, o al editarlo cambiando `assigned_to_user_id`, `AgendaReminderNotifier::assigned()` envía `AgendaItemAssignedNotice` (`type agenda.item.assigned`, nivel `important`, «Te asignaron una tarea») al asignado — nunca a quien hace la asignación. Llega por la isla como cualquier [aviso](avisos.md). Sustituye al antiguo evento `AgendaItemAssigned` y a su canal `agenda.user.{userId}`, que se emitía sin que ningún cliente lo escuchara (retirados el 2026-09-24).

## Rutas

Grupo `/{tenant}/agenda` (name `agenda.*`), middleware `role:admin-empresa|admin-sucursal|cajero|superadmin` dentro del stack tenant (`resolve.tenant` + `ensure.tenant`):

```
GET    /{tenant}/agenda                      agenda.index          ← Inertia Agenda/Index (atrasadas, hoy, próximas 7 días, notas, alertas)
GET    /{tenant}/agenda/calendario           agenda.calendar       ← JSON ocurrencias expandidas (?from&to, default mes actual).
                                                                    Devuelve el ítem completo (scope, branch_id, assigned_to_user_id,
                                                                    priority, recurrence, remind_at, state, body, owner) para que el
                                                                    panel del día pueda mostrarlo y abrirlo en el modal de edición.
GET    /{tenant}/agenda/alertas              agenda.alerts         ← JSON alertas derivadas (widget dashboards)
GET    /{tenant}/agenda/completadas          agenda.completadas    ← JSON historial paginado (30/página)
POST   /{tenant}/agenda                      agenda.store
POST   /{tenant}/agenda/ia/borrador          agenda.ia.store       ← propuesta IA (JSON, stateless)
PUT    /{tenant}/agenda/{item}               agenda.update
PATCH  /{tenant}/agenda/{item}/completar     agenda.complete
PATCH  /{tenant}/agenda/{item}/cancelar      agenda.cancel         ← cancel_reason opcional (max 255)
PATCH  /{tenant}/agenda/{item}/posponer      agenda.snooze         ← minutes 1–10080
PATCH  /{tenant}/agenda/{item}/visto         agenda.visto
DELETE /{tenant}/agenda/{item}               agenda.destroy        ← soft delete
GET    /{tenant}/agenda/{item}/ics           agenda.ics            ← descarga text/calendar
```

### Validaciones (`StoreAgendaItemRequest` / `UpdateAgendaItemRequest`)

| Campo | Reglas |
|---|---|
| `type` / `scope` / `priority` / `recurrence` | `Rule::enum(...)` de sus enums (priority/recurrence nullable) |
| `title` | required, max 160 · `body` nullable, max 5000 |
| `branch_id` | requerido solo si scope=`branch` **y** el usuario es admin-empresa/superadmin; `exists` acotado al tenant |
| `assigned_to_user_id` | nullable, `exists` de usuarios del tenant |
| `starts_at` | **requerido si `type=event`**; nullable date |
| `ends_at` | nullable, `after_or_equal:starts_at` |
| `remind_at` / `recurrence_until` | nullable date · `all_day` boolean |

## Frontend

Página única `resources/js/Pages/Agenda/Index.vue` con 4 pestañas (**Hoy · Calendario · Alertas · Completadas**). El **calendario** (`AgendaCalendar.vue`) es inspeccionable desde 2026-08-06: cada celda muestra hasta 3 pendientes con hora y franja de prioridad, `+N más` si hay más, las completadas plegadas a un contador (`✓ 2 hechas`) y puntos de densidad junto al número del día. Pulsar una fecha abre su detalle en el panel lateral; pulsar una tarea abre `AgendaItemModal` para editarla. Antes pintaba todos los ítems truncados, sin hora ni prioridad, y no respondía al clic. El componente expone `refresh()` porque carga su rango por `fetch` fuera del ciclo de Inertia: la página lo llama al guardar o completar. Resuelve el layout dinámicamente según `auth.role` (`EmpresaLayout` / `SucursalLayout` / `CajeroLayout`). "Hoy" muestra secciones Atrasadas (rojo, arriba), Hoy, Próximas y Notas (notas sin fecha). "Completadas" carga lazy vía fetch al abrir la pestaña. Botón WhatsApp en alertas de fiado (`wa.me`, prefija `52` a números de 10 dígitos).

Componentes (`resources/js/Components/Agenda/`):

- `AgendaItemModal.vue` — form crear/editar (tipo, scope — `company` solo visible para admin de empresa —, sucursal, asignado, fechas, all_day, remind_at, prioridad, recurrencia). Acepta prop de prefill con la propuesta IA y marca campos sugeridos; nunca prerellena la asignación.
- `AgendaCapturaIAModal.vue` — dictado: textarea + grabación de voz (`useAudioRecorder`, máx 90s).
- `AgendaCalendar.vue` — grilla mensual; pide `agenda.calendar` por rango al montar y al cambiar de mes; atenúa completadas.
- `AgendaTodayWidget.vue` — card "Agenda — alertas" (top 3) en los dashboards de Empresa, Sucursal y Caja; fetch único a `agenda.alerts`.

Los recordatorios del encabezado ya no son un componente de la agenda: los muestra la isla de avisos (`Components/Notifications/NotificationIsland.vue`, ver [avisos](avisos.md#la-isla-en-la-web)). `AgendaBell.vue` se retiró el 2026-09-24.

Composables: `useAgendaAiDraft.js` (submitDraft con axios/FormData, timeout 120s — espejo de `usePurchaseAiDraft`) y `useAudioRecorder.js` (compartido con Gastos/Compras).

## Dictado con IA

`POST agenda/ia/borrador` → `Ai\AgendaDraftController` → `AiAgendaDraftService`:

1. Valida `input_text` (max 2000) y/o `audio` (`mimes` tolerantes de MediaRecorder, ≤ 10 MB); exige al menos uno (422).
2. Whisper transcribe el audio (config de `ai.expenses.*`: `whisper-1`, idioma `es`).
3. Combina texto + `[Nota de voz transcrita]` y llama a GPT (chat.completions, `response_format: json_object`) con un system prompt en español que incluye el esquema/enums y "HOY" en `America/Mexico_City` para resolver "mañana a las 2pm" a datetime concreto.
4. `AiAgendaProposalParser` normaliza: **clamp** de `type`/`scope`/`recurrence`/`priority` a sus enums (valores inventados caen a defaults seguros; `priority` se descarta si `type ≠ task`), fechas parseadas en TZ México y devueltas ISO8601, `confianza` alta/media/baja (default `baja`). **Nunca incluye asignado.**
5. Respuesta `{ proposal, transcription }`; el frontend abre `AgendaItemModal` prellenado. **Nada se persiste** hasta que el usuario confirma con el `agenda.store` normal. Errores de OpenAI → 502 con mensaje neutro (`report()` del real).

## Export ICS

`IcsBuilder::forItem($item, $tenantSlug)` genera un `VCALENDAR`/`VEVENT` manual (sin dependencias): `UID agenda-{id}@{slug}`, fechas en UTC (`Ymd\THis\Z`; si no hay `starts_at` usa `now`, si no hay `ends_at` usa inicio + 1h), `SUMMARY`/`DESCRIPTION` escapados (`\; \, \n`), y si hay `remind_at` un bloque `VALARM` con `TRIGGER:-PT{min}M`. Se sirve con `Content-Type: text/calendar` + `Content-Disposition: attachment`, autorizado por `view` del policy.

## Riesgos y limitaciones

| Riesgo / limitación | Estado / mitigación |
|---|---|
| ~~`AgendaItemAssigned` se emitía por Reverb pero nadie lo escuchaba~~ | **Resuelto (2026-09-24)**: la asignación es un aviso guardado (`AgendaItemAssignedNotice`) que llega en vivo a la isla; el evento y su canal se retiraron. |
| Recordatorios sin push con la app cerrada | El aviso se genera a su hora aunque nadie esté conectado (comando por minuto, requiere el cron `schedule:run`) y espera en la bandeja; no hay WhatsApp/email/web-push. |
| El **hub** muestra el recordatorio con «Entendido», sin Hecho / +30 min | Lee la misma bandeja pero no tiene endpoints de agenda en `/api/v1/hub`: «Entendido» marca leído sin completar ni posponer el ítem. |
| Cross-tenant | `TenantScope` global + policy verifica `tenant_id` en toda acción + `visibleTo` en toda query de lectura. Tests de visibilidad cubren roles. |
| Recurrencia infinita al expandir calendario | Guard de 1000 iteraciones en `AgendaCalendarService` + corte por `recurrence_until`. |
| ~~Escalado del polling (N usuarios × 1 req/min)~~ | **Resuelto (2026-08-21)**: el polling se retiró. En producción era el request más frecuente después de la mesa de trabajo (708 + 661 hits entre dos tenants en el periodo medido) por un módulo de uso ocasional. Queda un request por carga de la app, no uno por minuto y pestaña. |
| ~~El badge no se actualizaba durante la sesión~~ | **Resuelto (2026-09-24)**: un recordatorio que vence con la app abierta llega en vivo a la isla. |
| Alertas derivadas pueden ser costosas (fiados vía `CollectionMetrics`) | Límites de 50 filas por fuente; solo se calculan on-demand (index y endpoint alerts). Ya no se calculan en cada carga de la app: la campana de agenda que lo hacía se retiró. |
| IA inventa enums/fechas o asigna personas | Parser clamp a enums, fechas inválidas → null, asignación imposible por diseño. Tests cubren clamps y el 502. |
| Completar recurrente cerca de `recurrence_until` | El clon solo se crea si la siguiente ocurrencia `<= recurrence_until` (endOfDay). |

## Tests

`tests/Feature/Agenda/` (12 archivos):

| Archivo | Cubre |
|---|---|
| `AgendaCrudTest` | crear personal, 403 de cajero con scope company, branch forzada al cajero, regeneración de recurrente al completar, descarga ICS |
| `AgendaVisibilityTest` | admin-empresa ve todas las sucursales; admin-sucursal ve company+branch+personal+asignadas; solo admin de empresa crea `company`; no editar personal ajeno |
| `AgendaStatesTest` | accessor `state` + scopes active/overdue/pending/history |
| `AgendaNotificationsTest` | cancelar con motivo, snooze mueve `remind_at` y limpia visto, marcar visto, historial completadas |
| `AgendaReminderNotifierTest` | destinatario (asignado / creador / nunca otro tenant), `remind` guarda la fila aunque el broadcast falle, `assigned`, `close` sólo cierra los del ítem, `rearm` |
| `DispatchAgendaRemindersCommandTest` | avisa una sola vez; ignora completados, cancelados, vistos, futuros y borrados; reavisa tras posponer; sigue si un envío falla; varios tenants |
| `AgendaReminderLifecycleTest` | cada acción del controlador cierra el aviso; posponer/editar hora/reasignar rearman; `PUT` sin cambios no toca nada; asignar notifica al asignado y no a quien asigna; clon recurrente sin marcas; `agenda.notificaciones` retirada |
| `AgendaReminderMigrationTest` | columna `reminder_notified_at`, backfill de lo de hace > 24 h e índice parcial |
| `AgendaRecurrenceTest` | expansión semanal en rango, ítem sin recurrencia aparece una vez |
| `AgendaAlertServiceTest` | cuentas por pagar por sucursal visible; **el servicio no escribe en BD** |
| `AgendaIcsTest` | ICS válido con VALARM |
| `AgendaAiDraftTest` | propuesta normalizada sin persistir nada, clamp de enums inválidos, priority descartada si no es task, audio→Whisper→parseo, 422 sin texto/audio, 502 si OpenAI falla |

```bash
vendor/bin/sail artisan test --compact tests/Feature/Agenda/
```

## Referencias internas

- [docs/superpowers/specs/2026-05-24-agenda-recordatorios-design.md](../superpowers/specs/2026-05-24-agenda-recordatorios-design.md) — diseño congelado v1 (modelo, alcances, alertas derivadas, ICS).
- [docs/superpowers/specs/2026-05-24-agenda-v2-estados-notificaciones-design.md](../superpowers/specs/2026-05-24-agenda-v2-estados-notificaciones-design.md) — v2: estados, historial, campana global por polling (campana retirada; ver la isla).
- [docs/superpowers/specs/2026-09-24-isla-web-design.md](../superpowers/specs/2026-09-24-isla-web-design.md) — la agenda como fuente de avisos y la isla en la web.
- `app/Http/Controllers/Agenda/AgendaController.php` — todos los endpoints del módulo.
- `app/Models/AgendaItem.php` — scopes de visibilidad/estado y accessor `state`.
- `app/Policies/AgendaItemPolicy.php` — autorización por scope + `createScope`.
- `app/Services/Agenda/` — `AgendaAlertService` (alertas derivadas), `AgendaCalendarService` (expansión de recurrencia), `AgendaReminderNotifier` (recordatorios y asignaciones como avisos), `IcsBuilder`.
- `app/Console/Commands/DispatchAgendaRemindersCommand.php` — `agenda:dispatch-reminders`, cada minuto.
- `app/Services/Ai/AiAgendaDraftService.php` + `AiAgendaProposalParser.php` — dictado IA stateless.
- [docs/modulos/gastos.md](gastos.md) / [docs/modulos/compras.md](compras.md) — patrón IA (Whisper + GPT + parser) del que la agenda es espejo sin persistencia.
