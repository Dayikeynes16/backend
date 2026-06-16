# Carnicería Hub — Migración de módulos web (Fase 1: núcleo de caja) — Diseño congelado

**Fecha:** 2026-06-01
**Estado:** Aprobado para Fase 1
**Autor:** colaboración con Claude (brainstorming + propuesta)

## En palabras simples (léelo primero)

La app web (hecha con Inertia: páginas renderizadas en el servidor) tiene ~20 módulos
para cajero y admin-sucursal (~167 rutas, 40+ pantallas). Queremos que esos módulos
también existan en el **hub de escritorio** (Electron), reusando la infraestructura
que ya construimos (login Sanctum, vue-router, shell por rol).

Esto es **demasiado grande para un solo proyecto**, así que lo tratamos como un
**programa por fases**. Esta primera fase:
1. **Define el patrón reutilizable** de migración (cómo un módulo Inertia se vuelve
   API JSON + pantalla en el hub) que copiaremos en cada módulo siguiente.
2. **Implementa el núcleo de caja**: abrir/cerrar **turno**, ver la **lista de ventas**
   por cobrar (las que llegan de las básculas), y **cobrar** una venta.

No rompemos nada: las básculas (`auth.apikey`) y la web (Inertia, sesión) quedan
**intactas**. Todo es aditivo.

## Decisiones tomadas (brainstorming 2026-06-01)

1. **Programa por fases.** Fase 1 = patrón + núcleo de caja (turno + ver ventas +
   cobrar). Los demás módulos serán specs cortos copiando el patrón.
2. **Alcance Fase 1 = esencial.** Abrir/cerrar turno, listar ventas activas, ver
   detalle, cobrar. **Difiere:** bloqueo/heartbeat, edición de ítems, asignar cliente,
   vincular pedidos, WhatsApp, cancelaciones.
3. **Online.** Turno y cobro operan contra el backend en vivo (como el login del Hito
   2). El buffer offline sigue siendo solo para ventas de báscula (Hito 1). El cobro/
   turno **offline** es una fase dedicada futura (subsistema delicado: dinero).
4. **Patrón = enfoque A.** Controladores API nuevos bajo `auth:sanctum` que reusan los
   servicios existentes; los controladores Inertia no se tocan.
5. **UI = Material Design 3** vía `@material/web` (componentes oficiales) en Vue.
6. **Lista de ventas por polling** (no Echo/tiempo real en esta fase).

## El patrón de migración reutilizable (receta para los ~20 módulos)

```
  Módulo web (Inertia)                    Módulo en el hub
  Controller Inertia ──┐                  ┌─ pantalla Vue (MD3)
  (no se toca)         │                  │  consume window.hub.api.*
                       ▼                  ▼
                  ┌─ Servicio ◄── API Hub Controller (auth:sanctum + rol)
                  │  (lógica)       /api/v1/hub/...  → API Resource (JSON)
                  └─ reutilizado    (nuevo, aditivo)
```

Cada módulo se migra con 4 pasos:

1. **Extraer lógica a servicio** (si vive dentro del controlador Inertia). Refactor que
   preserva comportamiento; el controlador Inertia pasa a llamar al servicio. Cubierto
   por los tests Inertia existentes.
2. **API Hub Controller** nuevo en `app/Http/Controllers/Api/Hub/<Modulo>Controller.php`,
   bajo `/api/v1/hub/*` con `auth:sanctum` + middleware de rol. Llama al servicio,
   devuelve **API Resources** JSON. Toma `branch_id`/`user` del **token**, no de la URL.
3. **Hub main — API cliente**: un módulo por dominio en `src/main/api/<modulo>.js` que
   hace las llamadas con el token; expuesto por IPC como `window.hub.api.<modulo>`. El
   token nunca toca el renderer.
4. **Hub renderer — pantalla Vue (MD3)** en `src/renderer/views/`, registrada en el
   shell por rol, consumiendo `window.hub.api.*`.

**Capa transversal compartida (se construye en Fase 1, la usan todos):**
- `httpClient` autenticado genérico en main (patrón de `AuthApiClient`: token Bearer,
  `401→logout`, errores tipados `offline|forbidden|conflict|validation|server`).
- Base de componentes/tema MD3 (`@material/web`) + tokens.
- Estados uniformes: cargando / error / sin conexión / sin permiso.

## Endpoints Fase 1

Todos bajo `/api/v1/hub/*`, con `auth:sanctum` + rol (cajero/admin-sucursal). El
`branch_id`/`user` salen del token.

| Endpoint | Reusa | Comportamiento |
| --- | --- | --- |
| `GET hub/shift/current` | `CashRegisterShift` | Turno abierto del usuario, o `null`. |
| `POST hub/shift/open` | lógica de `TurnoController@open` (extraída a `ShiftService`) | Abre turno; **409** si ya hay uno abierto. |
| `POST hub/shift/close` | `ShiftTotalsCalculator` + `ShiftCashOutCalculator` | Cierra turno; devuelve datos del corte. |
| `GET hub/sales` | query de ventas activas/pendientes de la sucursal | Lista para cobrar (polling). Paginada. |
| `GET hub/sales/{sale}` | — | Detalle + ítems + pagos. |
| `POST hub/sales/{sale}/payments` | servicio de recálculo de pago (ver nota de convergencia) | Registra cobro; calcula cambio; transiciona estado. **Requiere turno abierto.** |

**Reglas / contrato:**
- **Idempotencia de cobro (DISEÑO NUEVO, no copia de un patrón existente):** en este
  branch **no existe** ninguna columna `client_reference` (la de `sales` vive en la rama
  `feature/hub-backend-idempotencia`, aún sin mergear; `Api/SaleController@store` hoy
  deduplica folios solo con `pg_advisory_xact_lock`). Por tanto la idempotencia de pagos
  es **subsistema nuevo a construir**: columna `client_reference` nullable en `payments`
  + índice único parcial `(sale_id, client_reference)` (patrón Postgres
  `WHERE client_reference IS NOT NULL`, que sí tiene precedente) + **ruta de lectura de
  dedupe** (buscar pago existente por `(sale_id, client_reference)` antes de crear) y su
  manejo de concurrencia. Comportamiento idéntico al actual cuando es `null` (web Inertia).
- **Convergencia del recálculo de pago (DECISIÓN DE IMPLEMENTACIÓN):** el camino real de
  registro de pago `Sucursal/PaymentController@store` usa un método **privado
  `recalculate()` propio**, NO `SalePaymentService::recalculate` (este último solo lo
  usan `CustomerPaymentController` y `SaleItemEditor`). El paso 1 del patrón (extraer
  lógica a servicio) debe decidir explícitamente: **(a)** extraer ese `recalculate`
  privado a un servicio compartido y migrar también el camino Inertia (convergencia real,
  refactor mayor, cubierto por la regresión de pagos), o **(b)** aceptar dos
  implementaciones de recálculo divergentes. Recomendado **(a)** para no divergir el
  cálculo de dinero entre web y hub. Esto NO es "solo extraer la apertura de turno".
- **Cobrar exige turno abierto** del usuario; si no, **409** con mensaje claro.
- **Aislamiento:** toda query filtra por `branch_id` del usuario del token.
- Los controladores Inertia de turno/pagos **no se tocan en su contrato**; el refactor de
  extracción (turno: apertura → `ShiftService`; pago: recálculo → servicio compartido si
  se elige (a)) preserva comportamiento y queda cubierto por los tests Inertia existentes.

## Hub — capa API (main) y pantallas (renderer)

**Main (`src/main/api/`):**
- `httpClient.js`: cliente genérico con token Bearer (vía `AuthService`), `401→logout`,
  errores tipados. Base de todos los módulos.
- `shift.js` (current/open/close), `sales.js` (list/show/pay).
- IPC `api:shift:*`, `api:sales:*` → `window.hub.api.shift.*`, `window.hub.api.sales.*`.

**Renderer (Vue + @material/web):**
- Setup MD3 una vez: instalar `@material/web`, importar componentes, tokens de tema.
- `ShiftView.vue`: turno cerrado → **Abrir turno**; abierto → resumen + **Cerrar turno**
  (muestra corte).
- `SalesView.vue`: lista de ventas por cobrar (**polling** cada N s), estados; clic abre
  detalle.
- `SaleDetailView.vue`: ítems + total + pagos; formulario de **cobro** (monto, método,
  cambio). Bloqueado si no hay turno abierto.
- Integradas al `ShellLayout`: nav añade **Turno** y **Caja**. Monitor (Device) y Config
  siguen igual.
- Componentes base MD3 compartidos (botón, card, campo, diálogo, estados) para los
  próximos módulos.

## Manejo de errores y estados

| Situación | Backend | Hub |
| --- | --- | --- |
| Sin red | fetch falla | Banner "Sin conexión — reintentar"; no cobra/abre turno. |
| Token expirado/revocado | 401 | Cierra sesión → `/login` (Hito 2). |
| Rol sin permiso | 403 | "No tienes permiso para esta acción." |
| Cobrar sin turno abierto | 409 | "Abre un turno antes de cobrar." |
| Turno ya abierto | 409 | "Ya tienes un turno abierto." |
| Validación | 422 | Errores por campo. |
| Reintento de cobro | dedupe por `client_reference` (diseño nuevo, ver Endpoints) | No duplica; muestra el pago existente. |
| Cargando | — | Skeleton/spinner MD3. |

## Testing

- **Backend (PHPUnit):** turno (abrir / 409 si existe / cerrar con totales), ventas
  (lista filtra por sucursal del token, detalle), cobro (registra, cambio, transición,
  **idempotencia por `client_reference`**, 409 sin turno, aislamiento de sucursal).
  **Regresión:** suite de turno/pagos Inertia existente — en concreto
  `tests/Feature/Caja/TurnoCorteCashOutTest.php`, `tests/Feature/Caja/PagosIndexTest.php`,
  `tests/Feature/Sucursal/CashShiftCloseTest.php`, `tests/Feature/Sucursal/PagosSummaryTest.php`
  (cobertura por flujo, no hay test nombrado por controlador; si se elige convergencia (a),
  confirmar que estos ejercitan el `recalculate()` privado antes de confiar en ellos como
  red de seguridad) + básculas (`tests/Feature/PresentationSaleContractTest.php`) +
  `tests/Feature/Auth`.
  (Nota: `tests/Feature/Api/SaleIdempotencyTest.php` NO existe en este branch; vive en la
  rama de idempotencia sin mergear — no referenciarlo aquí.)
- **Hub (Vitest):** `httpClient` (mapeo de errores con fetch inyectado), `shift.js`/
  `sales.js` (forma de respuestas), lógica pura de "cobrar requiere turno". SQLite no
  interviene.
- **Verificación manual (Electron real):** abrir turno → ver ventas → cobrar → cerrar
  turno, contra Sail.

## Fuera de alcance (Fase 1)

Bloqueo/heartbeat, edición de ítems, asignar cliente, vincular pedidos web, WhatsApp,
solicitudes de cancelación; cobro/turno **offline** (fase dedicada futura); tiempo real
con Echo; módulos admin-sucursal (productos, categorías, métricas, proveedores, usuarios,
config, compras, gastos). Cada uno será su propio spec siguiendo el patrón.

## Roadmap posterior (fases siguientes, cada una su spec)

- Fase 2: Historial, Clientes (apoyo a caja).
- Fase 3: Gastos, Compras (cajero).
- Fase 4: módulos admin-sucursal (productos, categorías, métricas, proveedores, usuarios,
  config).
- Fase dedicada: cobro/turno offline con cola (sobre la idempotencia ya construida).
- Báscula apuntando al hub (pendiente, al final del programa).

## Notas de implementación

- Backend en rama propia (p. ej. `feature/hub-modulos-fase1`); hub en su repo.
- Usar skills: `material-3` (UI), electron en `carniceria-hub/.agents/skills/electron/`,
  `laravel-best-practices` (backend), `vue`/`vue-best-practices` (renderer).
- NO tocar la config de guards: el guard `web` por defecto (en `config/auth.php`) ya
  alinea con `auth:sanctum` y los roles Spatie (guard `web`). No hace falta `config/sanctum.php`
  (no existe) ni editar guards.
- El grupo `/api/v1/hub/*` es nuevo y separado del grupo `auth.apikey` (básculas).
