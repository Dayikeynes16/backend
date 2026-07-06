# Agenda

Agenda interna del tenant: tareas, eventos y notas ligados al tiempo, con alcance por empresa/sucursal/personal, recurrencia simple, recordatorios in-app, **alertas automáticas derivadas** de datos existentes (cuentas por pagar, fiados vencidos, turnos sin cerrar, API keys por expirar), dictado con IA (texto + voz) y exportación de eventos a `.ics`. Es el único módulo cuyo grupo de rutas comparten **los 4 roles** bajo `/{tenant}/agenda`.

## Responsabilidades

- CRUD de ítems manuales (`agenda_items`): tarea (`task`), evento (`event`) y nota (`note`).
- Visibilidad por alcance (`company` / `branch` / `personal`) + asignación de tareas a un usuario del tenant.
- Estados: pendiente, completada, **cancelada con motivo** y **atrasada** (derivada, no almacenada), con historial (pestaña Completadas).
- Recurrencia simple (`none · daily · weekly · monthly`) con expansión en memoria para el calendario y regeneración al completar.
- Recordatorios (`remind_at`) con acciones: posponer (snooze), marcar visto, completar, cancelar — desde la pantalla Agenda y la campana global.
- Calcular **alertas automáticas** de solo lectura (nunca escriben en BD) y presentarlas junto a los ítems manuales con un shape común.
- Prerellenar el formulario con una propuesta de IA a partir de dictado ("Recuérdame entregar carne a las 2pm mañana").
- Exportar un ítem como archivo `.ics` (con `VALARM`) para agregarlo al calendario del dispositivo.

**No hace** (fuera de alcance según specs v1/v2):

- Push con la app cerrada (cron + WhatsApp API / email / web-push).
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
| Recurrencia **sin materializar filas** | El calendario expande ocurrencias en memoria (`AgendaCalendarService`, guard de 1000 iteraciones). Al **completar** un ítem recurrente se clona la siguiente ocurrencia viva (respetando `recurrence_until` y desplazando `remind_at` con el mismo offset). |
| Posponer = mover `remind_at`, no un estado | `snooze` suma minutos (1 – 10080 = 7 días) y limpia `reminder_seen_at`. |
| Notificaciones por **polling HTTP cada 60s** (campana), no Echo | Sin cron ni consumidor Reverb en el MVP; ver "Riesgos y limitaciones". |
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
├─ completed_at?, cancelled_at?, cancel_reason? (255)
├─ priority?     string           ← low | normal | high        (enum AgendaPriority)
├─ recurrence    string def none  ← none | daily | weekly | monthly (enum AgendaRecurrence)
├─ recurrence_until? (date)
└─ timestamps + softDeletes
```

Índices: `(tenant_id, scope, branch_id)`, `(tenant_id, starts_at)`, `(tenant_id, type, completed_at)`, `(tenant_id, completed_at)`, `(tenant_id, cancelled_at)`, `assigned_to_user_id`. Migraciones: `2026_05_24_120000_create_agenda_items_table` (v1) + `2026_05_24_130000_add_states_to_agenda_items` (v2: cancelación + visto).

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

### Recordatorios (in-app, con la app abierta)

1. Al crear/editar se fija `remind_at`.
2. `GET agenda/notificaciones` devuelve recordatorios vencidos no vistos (`remind_at <= now`, `reminder_seen_at` null, límite 20), atrasadas (límite 10), alertas financieras y `counts`.
3. `AgendaBell.vue` (montada en los 4 layouts) hace **polling cada 60s** y muestra badge + dropdown con acciones rápidas: completar, posponer (`PATCH {item}/posponer` con `minutes`), marcar visto (`PATCH {item}/visto`).

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

Al crear (o editar hacia) un ítem con `assigned_to_user_id` distinto del creador, se despacha `AgendaItemAssigned` (`ShouldBroadcastNow`) al canal privado `agenda.user.{userId}` (autorizado en `routes/channels.php` por `user.id === userId`). **Nota:** hoy nadie lo consume en el frontend — ver limitaciones.

## Rutas

Grupo `/{tenant}/agenda` (name `agenda.*`), middleware `role:admin-empresa|admin-sucursal|cajero|superadmin` dentro del stack tenant (`resolve.tenant` + `ensure.tenant`):

```
GET    /{tenant}/agenda                      agenda.index          ← Inertia Agenda/Index (atrasadas, hoy, próximas 7 días, notas, alertas)
GET    /{tenant}/agenda/calendario           agenda.calendar       ← JSON ocurrencias expandidas (?from&to, default mes actual)
GET    /{tenant}/agenda/alertas              agenda.alerts         ← JSON alertas derivadas (widget dashboards)
GET    /{tenant}/agenda/completadas          agenda.completadas    ← JSON historial paginado (30/página)
GET    /{tenant}/agenda/notificaciones       agenda.notificaciones ← JSON para la campana (polling)
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

Página única `resources/js/Pages/Agenda/Index.vue` con 4 pestañas (**Hoy · Calendario · Alertas · Completadas**). Resuelve el layout dinámicamente según `auth.role` (`EmpresaLayout` / `SucursalLayout` / `CajeroLayout`). "Hoy" muestra secciones Atrasadas (rojo, arriba), Hoy, Próximas y Notas (notas sin fecha). "Completadas" carga lazy vía fetch al abrir la pestaña. Botón WhatsApp en alertas de fiado (`wa.me`, prefija `52` a números de 10 dígitos).

Componentes (`resources/js/Components/Agenda/`):

- `AgendaItemModal.vue` — form crear/editar (tipo, scope — `company` solo visible para admin de empresa —, sucursal, asignado, fechas, all_day, remind_at, prioridad, recurrencia). Acepta prop de prefill con la propuesta IA y marca campos sugeridos; nunca prerellena la asignación.
- `AgendaCapturaIAModal.vue` — dictado: textarea + grabación de voz (`useAudioRecorder`, máx 90s).
- `AgendaCalendar.vue` — grilla mensual; pide `agenda.calendar` por rango al montar y al cambiar de mes; atenúa completadas.
- `AgendaBell.vue` — campana global con badge (montada en `AuthenticatedLayout`, `EmpresaLayout`, `SucursalLayout` y `CajeroLayout`): **polling `agenda.notificaciones` cada 60s** con acciones completar/posponer/visto inline.
- `AgendaTodayWidget.vue` — card "Agenda — alertas" (top 3) en los dashboards de Empresa, Sucursal y Caja; fetch único a `agenda.alerts`.

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
| **`AgendaItemAssigned` se emite por Reverb pero nadie lo escucha**: `AgendaBell.vue` usa polling HTTP cada 60s, no Echo | **Limitación conocida y deliberada** (spec v2: "Consumir el evento Reverb se difiere"). El aviso de asignación llega en el siguiente ciclo de polling (≤ 60s). El canal `agenda.user.{userId}` ya está autorizado en `routes/channels.php`, listo para cuando se conecte Echo. |
| Recordatorios solo funcionan **con la app abierta** | Por diseño MVP (sin cron/push). Fase futura: Scheduler + WhatsApp/email/web-push. |
| Cross-tenant | `TenantScope` global + policy verifica `tenant_id` en toda acción + `visibleTo` en toda query de lectura. Tests de visibilidad cubren roles. |
| Recurrencia infinita al expandir calendario | Guard de 1000 iteraciones en `AgendaCalendarService` + corte por `recurrence_until`. |
| Escalado del polling (N usuarios × 1 req/min) | Aceptado en MVP; las queries de la campana llevan `limit(20)`/`limit(10)`. |
| Alertas derivadas pueden ser costosas (fiados vía `CollectionMetrics`) | Límites de 50 filas por fuente; solo se calculan on-demand (index, endpoint alerts, notificaciones). |
| IA inventa enums/fechas o asigna personas | Parser clamp a enums, fechas inválidas → null, asignación imposible por diseño. Tests cubren clamps y el 502. |
| Completar recurrente cerca de `recurrence_until` | El clon solo se crea si la siguiente ocurrencia `<= recurrence_until` (endOfDay). |

## Tests

`tests/Feature/Agenda/` (8 archivos):

| Archivo | Cubre |
|---|---|
| `AgendaCrudTest` | crear personal, 403 de cajero con scope company, branch forzada al cajero, regeneración de recurrente al completar, descarga ICS |
| `AgendaVisibilityTest` | admin-empresa ve todas las sucursales; admin-sucursal ve company+branch+personal+asignadas; solo admin de empresa crea `company`; no editar personal ajeno |
| `AgendaStatesTest` | accessor `state` + scopes active/overdue/pending/history |
| `AgendaNotificationsTest` | endpoint notificaciones (due + overdue), cancelar con motivo, snooze mueve `remind_at` y limpia visto, marcar visto, historial completadas |
| `AgendaRecurrenceTest` | expansión semanal en rango, ítem sin recurrencia aparece una vez |
| `AgendaAlertServiceTest` | cuentas por pagar por sucursal visible; **el servicio no escribe en BD** |
| `AgendaIcsTest` | ICS válido con VALARM |
| `AgendaAiDraftTest` | propuesta normalizada sin persistir nada, clamp de enums inválidos, priority descartada si no es task, audio→Whisper→parseo, 422 sin texto/audio, 502 si OpenAI falla |

```bash
vendor/bin/sail artisan test --compact tests/Feature/Agenda/
```

## Referencias internas

- [docs/superpowers/specs/2026-05-24-agenda-recordatorios-design.md](../superpowers/specs/2026-05-24-agenda-recordatorios-design.md) — diseño congelado v1 (modelo, alcances, alertas derivadas, ICS).
- [docs/superpowers/specs/2026-05-24-agenda-v2-estados-notificaciones-design.md](../superpowers/specs/2026-05-24-agenda-v2-estados-notificaciones-design.md) — v2: estados, historial, campana global por polling.
- `app/Http/Controllers/Agenda/AgendaController.php` — todos los endpoints del módulo.
- `app/Models/AgendaItem.php` — scopes de visibilidad/estado y accessor `state`.
- `app/Policies/AgendaItemPolicy.php` — autorización por scope + `createScope`.
- `app/Services/Agenda/` — `AgendaAlertService` (alertas derivadas), `AgendaCalendarService` (expansión de recurrencia), `IcsBuilder`.
- `app/Services/Ai/AiAgendaDraftService.php` + `AiAgendaProposalParser.php` — dictado IA stateless.
- [docs/modulos/gastos.md](gastos.md) / [docs/modulos/compras.md](compras.md) — patrón IA (Whisper + GPT + parser) del que la agenda es espejo sin persistencia.
