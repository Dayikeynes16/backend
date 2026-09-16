# API del Hub de Escritorio (`/api/v1/hub/*`)

Superficie API que consume **Carnicería Hub**, la app de escritorio (Electron, repo separado `carniceria-hub/` en el workspace) que corre en cada sucursal. El hub funciona *offline-first* con un outbox local: encola operaciones cuando no hay conexión y las reintenta al reconectar (de ahí la idempotencia de pagos, ver abajo).

**Base URL:** `/api/v1/hub/`
**Autenticación:** token Sanctum en header `Authorization: Bearer {token}`
**Formato:** JSON · UTF-8

## Diferencias vs la API de básculas (`X-Api-Key`)

| | API básculas (`/api/v1/*`) | API hub (`/api/v1/hub/*`) |
|---|---|---|
| Autenticación | `X-Api-Key` (SHA-256, sin usuario) | Sanctum Bearer token (usuario real) |
| Identidad | Sucursal (la key pertenece a un branch) | Usuario + su `branch_id`/`tenant_id` |
| Roles | No aplica | Solo `cajero` y `admin-sucursal` (middleware `hub.role`) |
| Alcance | Crear/consultar ventas y catálogo | Operación completa de caja: turnos, cobros, clientes, gastos, compras, proveedores, config |
| Rate limit | 60 req/min por key | Solo el login tiene throttle (10/min) |

Ambos grupos viven en `routes/api.php` y son independientes de la sesión web Inertia: nada del hub afecta a las básculas ni a la web.

## Autenticación (`/api/v1/auth/*`)

**Controller:** `Api\AuthController`

| Método | Ruta | Middleware | Descripción |
|--------|------|-----------|-------------|
| POST | `/api/v1/auth/login` | `throttle:10,1` | Login con email/contraseña, emite token Sanctum |
| GET | `/api/v1/auth/me` | `auth:sanctum` | Datos del usuario del token |
| POST | `/api/v1/auth/logout` | `auth:sanctum` | Revoca el token actual |

**POST /api/v1/auth/login** — body: `email`, `password`, `device_name` (máx. 120 chars, nombra el token).

```json
{
    "token": "1|abc...",
    "user": {
        "id": 4, "name": "Cajero Demo", "email": "cajero@eltoro.test",
        "role": "cajero", "branch_id": 1, "branch_name": "Sucursal Centro",
        "cashier_expenses_enabled": true, "cashier_purchases_enabled": true,
        "branch_admin_expense_categories_enabled": false,
        "tenant_id": 1, "tenant_slug": "el-toro"
    }
}
```

> `cashier_expenses_enabled` / `cashier_purchases_enabled` son los feature-flags de la sucursal; el hub los usa para mostrar/ocultar Gastos y Compras al cajero en la navegación, con la misma regla que la web (`CajeroLayout`). `branch_admin_expense_categories_enabled` habilita la pestaña Categorías de Gastos para el admin-sucursal (misma regla que la web).

**Errores:** `401` credenciales incorrectas · `403` el usuario no tiene rol de hub · `409` el usuario tiene `force_password_change` pendiente (se resuelve in-app con el endpoint siguiente).

**POST /api/v1/auth/change-password** (throttle 10/min) — cambio de contraseña forzado, para el flujo del `409` de login (en ese punto no existe token, así que autentica por credenciales). Body: `email`, `password` (la temporal), `new_password` + `new_password_confirmation` (reglas `Password::defaults()` + `confirmed`, como el `ForcePasswordChangeController` web), `device_name`. En éxito actualiza la contraseña, limpia `force_password_change` y responde igual que login (`token` + `user`), así el hub entra directo. **Errores:** `401` credenciales incorrectas · `403` sin rol de hub · `409` el usuario NO tiene el flag activo (`No necesitas cambiar tu contraseña.`) · `422` validación de la nueva contraseña.

## Roles y scoping

- **Middleware `hub.role`** (`App\Http\Middleware\EnsureHubRole`): todo `/api/v1/hub/*` exige rol `cajero` o `admin-sucursal`. Otros roles → `403`. `admin-empresa` y `superadmin` operan solo por web.
- **Scoping por sucursal:** los controllers del hub no usan `ResolveTenant` (no hay slug en la URL). Resuelven los recursos con `withoutGlobalScopes()` filtrando por el `branch_id` del usuario del token; un recurso de otra sucursal devuelve `404`. Para modelos con `TenantScope` que se reusan de la web (ApiKey, Provider, Expense…), el controller fija `app()->instance('tenant', $user->tenant)`.
- **Endpoints solo admin-sucursal** (el cajero recibe `403`): toda la sección Config, toda la sección Proveedores y la cancelación de cobros globales de fiado.
- **Toggles por sucursal:** Gastos requiere `cashier_expenses_enabled`, Compras `cashier_purchases_enabled`, y crear/editar proveedores `branch_admin_providers_enabled` (todos en `branches`; si están apagados → `403`).

## Dashboard

| Método | Ruta | Rol | Descripción |
|--------|------|-----|-------------|
| GET | `dashboard` | ambos | KPIs del día de la sucursal |

`Api\Hub\DashboardController` espeja la riqueza del dashboard web (admin-sucursal) para el panel de Inicio del hub. Devuelve:

- **`today`** — ventas del día no canceladas (`sales_count`, `sales_total`, `avg_ticket`, `pending_total`/`pending_count`), comparativa vs. ayer (`sales_total_yesterday`, `sales_delta_pct`), gastos (`expenses_total`, `expenses_total_yesterday`, `expenses_delta_pct`, `expenses_count`), `collected_total`, `cancel_request_count` y `product_count` (activos). La atribución de fecha de las ventas usa la **fecha canónica `COALESCE(completed_at, created_at)`** (2026-07-11), la misma que el dashboard web y Métricas, para que los números cuadren entre superficies.
- **`by_method` / `by_method_count`** — cobranza del día por `cash`/`card`/`transfer` (importe y número de pagos).
- **`hourly` / `hourly_yesterday`** — serie de ventas por hora (arrays de 24 con `{ h, sales, trx }`) para la gráfica hoy vs. ayer; **`expenses_hourly`** (24 × `{ h, amount }`) para el sparkline de gastos.
- **`top_products`** (top 5 por importe) y **`top_expense_categories`** (top 5 categorías de gasto del día).
- **`recent_sales`** (8 últimas), **`recent_expenses`** (5 últimos) y **`recent_shifts`** (5 cortes cerrados: `user`, `closed_at`, `total_sales`, `sale_count`).
- **`shift`** — conciliación en vivo del turno abierto vía `ShiftService::summary`, o `null`.

`sales_delta_pct` / `expenses_delta_pct` son la variación porcentual vs. ayer, o `null` si ayer no hubo base de comparación. Los importes por hora y comparativas se calculan en Postgres con `FILTER (WHERE …)` y `EXTRACT(HOUR FROM created_at)`.

## Configuración de sucursal (solo admin-sucursal)

**Controller:** `Api\Hub\ConfigController`. Configuración de *negocio* (la config técnica del hub — puerto, backend URL — vive en el proceso main de Electron).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `config` | Datos de la sucursal + métodos de pago habilitados + lista de API keys |
| GET | `config/payment-methods` | Métodos de pago habilitados de la sucursal. **Ambos roles**, a diferencia de `GET config` (solo admin: ahí viven las API keys). El hub lo cachea para poder cobrar sin red |
| PUT | `config/payment-methods` | Actualiza `payment_methods_enabled` (body: `payment_methods[]`, mín. 1, subconjunto de los soportados) |
| POST | `config/api-keys` | Genera una API key de báscula (body: `name`, `expires_in_days` opcional 1–365). Devuelve `raw_key` **una sola vez** (para el QR de vinculación); solo se persiste el hash |
| DELETE | `config/api-keys/{id}` | Revoca (marca `inactive`) |
| DELETE | `config/api-keys/{id}/force` | Elimina definitivamente. `422` si sigue activa y no expirada (hay que revocar primero) |

## Turno (caja)

**Controller:** `Api\Hub\ShiftController` (reusa `ShiftService`).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `shift/current` | Turno abierto del usuario (`data: null` si no hay) + `summary` de conciliación en vivo (esperado, totales por método, salidas, y `sales_generated` — "Vendido vs Cobrado") |
| POST | `shift/open` | Abre turno (body: `opening_amount`, `opened_at` y `client_reference`, todos opcionales). `201` al crear, `200` si esa referencia ya produjo un turno, `409` si ya tiene uno abierto y no mandó referencia |
| POST | `shift/close` | Cierra turno (body: `declared_amount`, `declared_card`, `declared_transfer`, `notes`, todos opcionales). Devuelve el corte completo: shift cerrado + `summary` + `verdict` (ShiftVerdictService: veredicto neto con compensación cruzada) + `whatsapp` (`{url, has_owner_whatsapp}` — reporte al dueño vía ShiftReportMessageService) |
| GET | `shifts?from=&to=&page=` | Cortes históricos paginados (15). Admin: toda la sucursal; cajero: solo los suyos. Filas con totales, declarado y `difference_total` |
| GET | `shifts/{id}` | Corte persistente: `data` + `summary` + `verdict` + `whatsapp`. Cajero solo los propios (403 ajeno); cross-branch 404 |
| POST | `shifts/{id}/recalculate` | **admin-sucursal.** Recomputa totales/diferencias respetando declarados originales (NULL no se resucita). Devuelve el corte actualizado. `422` si el turno está abierto |
| POST | `shifts/{id}/reopen` | **admin-sucursal.** Reabre el turno (resetea el corte a valores neutros). `422` si ya está abierto o si el cajero tiene otro turno abierto |
| POST | `shift/withdrawals` | Registra un retiro de efectivo sobre el turno abierto (body: `amount` > 0, `reason` ≤ 255). `201` con el retiro + `summary` fresco; `404` si no hay turno abierto. Controller: `Api\Hub\WithdrawalController` |
| DELETE | `shift/withdrawals/{id}` | Elimina un retiro. El cajero dueño solo en su turno abierto; admin-sucursal también con turno cerrado; `403` fuera de la sucursal/tenant. Devuelve `summary` fresco (o `null` sin turno abierto) |

**Abrir turno desde un hub que estuvo sin internet.** `POST shift/open` acepta dos campos opcionales: **`opened_at`** (ISO-8601, la hora real en que se abrió la caja) y **`client_reference`** (≤64, idempotencia).

El servidor **acota** `opened_at` en vez de aceptarla o rechazarla: `min( max(propuesta, cierre del turno anterior, ahora − 6 h), ahora )`. No es una validación cosmética — la ventana del corte es `whereBetween(created_at, [opened_at, closed_at])` filtrada por usuario y **sin FK al turno**, así que dos ventanas solapadas contarían los mismos pagos dos veces; y `opened_at` es además frontera de autorización para editar comprobantes. Se acota y no se rechaza porque el hub está offline y no puede negociar: un `422` dejaría la caja sin abrir con gente esperando.

La hora se convierte explícitamente de UTC a la zona de la app (`America/Mexico_City`), o se colarían seis horas de desfase en la columna que define la ventana del dinero.

Un reintento con la misma `client_reference` devuelve **`200`** con el turno existente; **sin** referencia, dos aperturas siguen chocando con **`409`**. Spec: `carniceria-hub/docs/superpowers/specs/2026-08-21-cobrar-sin-internet-design.md`.

Las reglas de retiros son las mismas que en la web: viven en `ShiftService::addWithdrawal` / `removeWithdrawal`, compartidas por `Sucursal\WithdrawalController` (web) y `Api\Hub\WithdrawalController` (hub).

El turno abierto es requisito para cobrar ventas, registrar gastos, registrar compras y pagos en efectivo a proveedores (`409` si no hay). Eso incluye los **dos** caminos de pago a proveedor —contra una compra (`purchases/{id}/payments`) y a cuenta (`providers/{id}/pagos`)—: es el mismo dinero del mismo cajón, así que ambos se atan al turno vía `cash_register_shift_id` y el corte los descuenta del efectivo esperado (`cashProviderPayments`).

> 2026-09-08: el pago **a cuenta** no exigía turno ni se ataba a él. El dinero salía del cajón sin que el corte se enterara y quien cerraba aparecía con un faltante por ese importe. La web (Empresa/Sucursal) y el asistente IA no cambian: ahí el pago a cuenta lo hacen roles administrativos, que no tienen turno.

## Ventas y cobros

**Controllers:** `Api\Hub\SaleController` + `Api\Hub\PaymentController`. Las ventas llegan de las básculas (API `X-Api-Key`); el hub las cobra.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `sales?status=active\|pending\|all` | Ventas activas/pendientes de la sucursal (máx. 50) + `counts` por estado |
| GET | `sales/{id}` | Detalle con items/pagos/cliente. Incluye `payment_methods` habilitados de la sucursal y datos de `branch` (para el ticket) |
| POST | `sales/{id}/payments` | Registra un pago (ver contrato abajo) |
| PATCH | `sales/{id}/status` | Pausar/reactivar: el cajero solo puede transicionar `active` ↔ `pending` (`403` otra transición, `422` si el estado no lo permite) |
| POST | `sales/{id}/request-cancel` | Solicita cancelación (body: `cancel_request_reason`, la aprueba un admin). `422` si ya está cancelada o ya hay solicitud |
| PUT | `sales/{id}/payments/{pid}` | **admin-sucursal** — Corrige método/monto de un pago (tope: total − otros pagos; `422` si el pago es hijo de un cobro global o la venta está cancelada). Recalcula la venta vía `SalePaymentService` y devuelve el detalle fresco |
| DELETE | `sales/{id}/payments/{pid}` | **admin-sucursal** — Elimina un pago (soft) y recalcula. Mismas protecciones que PUT |
| POST | `sales/{id}/cancel` | **admin-sucursal** — Cancelación DIRECTA (body: `cancel_reason` requerido): borra pagos, marca `cancelled` y recalcula cortes cerrados afectados si estaba completada. Paridad con `Sucursal\Workbench::cancel` |
| POST | `sales/{id}/reopen` | **admin-sucursal** — Reabre una venta completada (`completed` → `active`, recalcula pendiente con los pagos existentes; `422` si no está completada) |
| PATCH | `sales/{id}/customer` | Asigna/desasigna cliente (`customer_id` o `null`) y aplica precios preferenciales (reusa `AssignCustomerToSale`) |
| GET | `sales/{id}/whatsapp` | Link `wa.me` del ticket; `reason=needs_phone` si la venta no tiene teléfono |
| POST | `sales/{id}/whatsapp-phone` | Captura un teléfono (10 dígitos): **resuelve o crea el cliente** de la sucursal y lo asocia a la venta. Acepta `confirmed` y `skip_assign`. Si asignarlo cambiaría el total o dejaría la venta cobrada, responde `requires_confirmation` con `preview` y no toca nada; reenviar con `confirmed:true` aplica, y `skip_assign:true` guarda solo `contact_phone` como antes. Ver [Teléfonos y resolución de clientes](../modulos/clientes-telefonos.md) |
| DELETE | `sales/{id}/whatsapp-phone` | Quita el teléfono guardado (`{ok:true}`; paridad `destroyWhatsappPhone` web). `HubSaleResource` expone `contact_phone` y, por item, `updated_by`/`created_at`/`updated_at` (badge "Editado"). El bloque `customer` incluye `name_pending`: el hub lo necesita para no tapar el nombre dictado con el placeholder `Cliente 55 1234 5678` (ver [ventas](../modulos/ventas.md#cuando-la-venta-lleva-los-dos-nombres)) |
| POST | `sales/{id}/lock` | Adquiere el lock de concurrencia (5 min). `409` con `locked_by_name` si otro usuario lo tiene. Adquirir uno libera los locks previos del usuario |
| POST | `sales/{id}/unlock` | Libera el lock (solo si es propio) |
| POST | `sales/{id}/heartbeat` | Renueva `locked_at` (mantiene vivo el lock) |

### Solicitudes de cancelación (solo admin-sucursal)

**Controller:** `Api\Hub\CancelRequestController` (paridad con `Sucursal\CancelRequestController`).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `cancel-requests?from=&to=` | Solicitudes pendientes (independientes del rango) + `stats` de canceladas del rango (default: hoy), `top_reasons` (5) e `history` (máx. 100) |
| POST | `cancel-requests/{id}/approve` | Aprueba: body `cancel_reason` opcional (vacío = usa el motivo de la solicitud; `422` si no hay ninguno). Borra pagos, cancela y recalcula cortes si estaba completada; responde `recalculated_shifts` |
| POST | `cancel-requests/{id}/reject` | Rechaza: limpia los campos de solicitud y la venta se conserva |

### POST `sales/{id}/payments` — contrato e idempotencia

Requiere turno abierto (`409` si no). `422` si la venta está `completed` o `cancelled`.

**Body:**

```json
{ "method": "cash", "amount": 500.00, "client_reference": "hub-a1b2c3d4" }
```

- `method`: obligatorio, dentro de los métodos habilitados de la sucursal.
- `amount`: obligatorio, > 0. Si excede lo pendiente, solo se aplica lo pendiente y la diferencia se devuelve como `change` (cambio).
- `client_reference`: opcional, string máx. 64. **Clave de idempotencia generada por el hub.**

**Idempotencia por `(sale_id, client_reference)`:** si ya existe un pago de esa venta con ese `client_reference`, el endpoint devuelve **el pago existente sin crear otro** — respuesta `200` (con `change: 0.0`) en lugar de `201`. Esto hace seguro el reintento del outbox del hub tras timeouts o cortes de red. Está garantizado en base de datos por un índice único parcial (migración `2026_06_01_000001_add_client_reference_to_payments_table.php`: `UNIQUE (sale_id, client_reference) WHERE client_reference IS NOT NULL`); los pagos de la web Inertia dejan la columna en `null` y no participan.

Esa garantía cubre también los **reintentos simultáneos**: si dos peticiones con la misma referencia llegan a la vez, ninguna ve a la otra en la consulta previa —la primera aún no ha confirmado— y ambas llegan al `INSERT`. El índice frena a la segunda, y el controlador lo trata como lo que es (el mismo cobro llegando dos veces): captura la violación de unicidad, relee el pago ya registrado y responde `200`. Antes salía un `500` que el hub leía como fallo del servidor y volvía a reintentar un cobro ya hecho.

**Respuesta 201 (pago nuevo):**

```json
{
    "payment": { "id": 99, "method": "cash", "amount": 450.00 },
    "change": 50.00,
    "sale": { "id": 456, "folio": "S-00456", "status": "completed", ... }
}
```

`SalePaymentService::recalculate` actualiza `amount_paid`/`amount_pending` y transiciona el estado de la venta (p. ej. a `completed` al liquidarse).

## Historial

| Método | Ruta | Rol | Descripción |
|--------|------|-----|-------------|
| GET | `history` | ambos | Historial de ventas de la sucursal (alcance por rol) |

`Api\Hub\HistoryController`. Query params: `date` (default hoy), `product` (búsqueda en el nombre de las partidas), `min_total`, `max_total`. Paginado (20) + `summary` (`count`, `total`) sobre todo el conjunto filtrado.

**Alcance por rol (paridad con la web):**

- **admin-sucursal** → TODAS las ventas de la sucursal del día (fecha canónica `COALESCE(completed_at, created_at)`; estados `Completed`/`Pending`/`Fulfilled`; excluye pedidos web pendientes). Espeja `Sucursal\SaleHistoryController`.
- **cajero** → solo las ventas donde registró al menos un pago. Espeja `Caja\HistorialController`.

Así la búsqueda por producto de la admin opera sobre todo el historial de la sucursal, no solo sobre las ventas que ella cobró.

## Edición de items de venta (solo admin-sucursal)

**Controller:** `Api\Hub\SaleItemController` (reusa `SaleItemEditor`, el mismo dominio que la Mesa de Trabajo web: solo ventas Active/Pending, respeta el lock, recálculo vía `SalePaymentService`, historial en `sale_item_changes`).

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `sales/{id}/items` | Agrega item (`product_id`, `presentation_id` opcional, `quantity`, `unit_price`, `notes`, `reason`). `201` con la venta fresca |
| PATCH | `sales/{id}/items/{iid}` | Edita cantidad/precio (`reason` según config). `422` si no hay cambios (no-op) |
| DELETE | `sales/{id}/items/{iid}` | Soft-delete del item; `reason` SIEMPRE obligatorio |
| GET | `sales/{id}/items-history` | Historial de cambios (`changes[]`: event, before/after, diff, reason, user) |

El `reason` en add/update es obligatorio si la sucursal tiene `sale_item_edit_reason_mode = required`; el `show` de la venta expone `can_edit_items` y `sale_item_edit_reason_mode` para la UI. `GET products` acepta `with_presentations=1` (sale_mode + presentaciones activas) para el formulario de agregar.

## Clientes y fiado

**Controllers:** `Api\Hub\CustomerController`, `CustomerPaymentController`, `CustomerPriceController`.

En la columna **Rol**, «módulo» significa admin-sucursal siempre, y cajero solo si su sucursal tiene `cashier_customers_enabled` (ver el recuadro de permisos abajo).

| Método | Ruta | Rol | Descripción |
|--------|------|-----|-------------|
| GET | `customers` | ambos | Lista paginada (25) con deuda/compras agregadas + `summary` de cartera. Filtros: `search` (nombre/teléfono), `status`, `with_debt`, `sort=name\|debt\|last_sale`. **Sin el módulo** degrada a libreta de contactos: `id, name, name_pending, phone` de los activos, sin `summary` ni agregados — lo justo para el selector de cliente de la venta |
| POST | `customers` | módulo | Alta (`name`, `phone` único por sucursal, `notes`) |
| GET | `customers/{id}` | módulo | Detalle + `stats` (gastado, pagado, deuda, ticket promedio, producto top por gasto acumulado, mismo criterio que la web) + precios preferenciales, estos **solo para admin-sucursal** (al cajero le llega `prices: []`) |
| PATCH | `customers/{id}` | módulo | Edición; el `status` solo lo aplica el **admin-sucursal** (al cajero se le ignora) |
| DELETE | `customers/{id}` | **admin-sucursal** | Si tiene ventas → desactiva (`action: "deactivated"`); si no → borra |
| GET | `customers/{id}/history` | módulo | Compras del cliente paginadas (25), no canceladas |
| GET | `customers/{id}/payments` | módulo | Ledger de fiado: ventas pendientes + últimos 30 cobros globales + `total_owed` + métodos de pago |
| GET | `customers/{id}/payments/{pid}` | módulo | Detalle de un cobro global: aplicaciones por venta, cajero, notas |
| POST | `customers/{id}/payments` | módulo | **Cobro global FIFO** (ver abajo); exige turno abierto |
| DELETE | `customers/{id}/payments/{pid}` | **admin-sucursal** | Cancela un cobro global (`cancel_reason`): borra los pagos hijos, recalcula ventas y turnos cerrados afectados |
| POST | `customers/{id}/prices` | **admin-sucursal** | Precio preferencial (`product_id`, `price`); único por producto |
| PATCH | `customers/{id}/prices/{pid}` | **admin-sucursal** | Actualiza `price` |
| DELETE | `customers/{id}/prices/{pid}` | **admin-sucursal** | Elimina el precio |

> **Paridad de permisos (actualizado 2026-08-21).** Desde el 2026-08-05 el cajero gestiona clientes cuando su sucursal tiene `cashier_customers_enabled` —el flag lo enciende el **admin-empresa** en la ficha de la sucursal, no el admin-sucursal—, igual que en la web (`routes/web.php`, grupo `branch.feature:cashier_customers_enabled`). Lo aplica el trait `AuthorizesHubCustomerManagement`, y cubre tanto las escrituras como las **lecturas de la ficha** (detalle, historial y ledger), porque en la web todas viven dentro de ese mismo grupo.
>
> Tres exclusiones del cajero se mantienen aunque el módulo esté encendido, calcadas de la web: **precios preferenciales** (ni los escribe ni los lee), **dar de baja un cliente** (ni por `DELETE` ni colando `status` en el `PATCH`) y **cancelar un cobro global**.
>
> `GET customers` queda deliberadamente fuera del gate porque alimenta el selector de cliente de la mesa de trabajo, que la web también entrega sin flag (`Caja\WorkbenchController` pasa `customers` siempre) — pero recortado a libreta, con el mismo payload que la web da allí.
>
> Cobertura: `tests/Feature/Api/Hub/CustomerCashierAccessTest.php`.

**Cobro global (`POST customers/{id}/payments`):** requiere turno abierto (`409`). Body: `amount_received`, `method`, `excluded_sale_ids[]` opcional, `notes`. Distribuye el abono FIFO (venta más antigua primero) sobre las ventas con saldo del cliente, creando un `Payment` por venta ligado a un `CustomerPayment` con folio `CG-00001`. Solo `cash` admite cambio; con otros métodos el monto no puede exceder la deuda (`422`). Responde `201` con el `customer_payment` y el detalle `applied` por venta. Usa advisory lock de PostgreSQL por sucursal para evitar cobros concurrentes.

## Productos (apoyo)

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `products?search=` | Catálogo activo de la sucursal (`id`, `name`, `price`, `unit_type`; máx. 50). Apoyo de formularios (p. ej. precios preferenciales) |
| GET | `purchase-products?search=` | Catálogo tenant-wide de productos de compra (`id`, `name`, `unit`) para autocompletar el formulario de compras |

## Categorías de gasto (solo admin-sucursal, requiere toggle `branch_admin_expense_categories_enabled`)

**Controller:** `Api\Hub\ExpenseCategoryController`. Paridad con la pestaña "Categorías" web del admin-sucursal (`HandlesExpenseCategoryWrites` / `HandlesExpenseSubcategoryWrites` + `ExpenseCategoryWriter`): catálogo **tenant-wide**, crear/editar categoría y subcategoría con `name`/`description`/`aliases`/`status`, **sin borrado** (reservado a empresa). El gate replica el middleware web `branch.feature:branch_admin_expense_categories_enabled` (`403 Tu empresa no ha habilitado esta función para tu sucursal.`). Aliases se normalizan (trim + dedupe case-insensitive).

| Método | Ruta | Descripción |
| --- | --- | --- |
| GET | `/api/v1/hub/expense-categories` | Catálogo completo (incluye inactivas), ordenado por nombre, con subcategorías |
| POST | `/api/v1/hub/expense-categories` | Crea categoría (`name` único por tenant, nace `active`) → `201` |
| PATCH | `/api/v1/hub/expense-categories/{id}` | Edita `name`/`description`/`aliases`/`status` |
| POST | `/api/v1/hub/expense-subcategories` | Crea subcategoría (`expense_category_id` + `name`, único en su categoría) → `201` |
| PATCH | `/api/v1/hub/expense-subcategories/{id}` | Edita subcategoría (mismos campos + `status`) |
| POST | `/api/v1/hub/expense-categories/ai-draft` | Borrador de categoría por IA: `input_text` (máx. 2000) y/o `audio` (voz, máx. 10 MB; **sin imágenes**, como la web). Devuelve `{draft_id, status, proposal, audio_transcription}`; `proposal.action` ∈ crear_categoria/usar_existente/crear_subcategoria/necesita_aclaracion. Reusa `AiCategoryDraftService` (Whisper + gpt-4o) |
| POST | `/api/v1/hub/expense-categories/ai-apply` | Aplica la propuesta revisada. Payload idéntico al web (`mode` create_new/use_existing + `category`/`category_updates` + `subcategories` máx. 8). Reuso literal de `HandlesExpenseCategoryWrites::storeFromAiDraft` |

## Gastos (requiere toggle `cashier_expenses_enabled`)

**Controller:** `Api\Hub\ExpenseController`. Reglas por rol (paridad web, 2026-07-11): el gasto del **cajero** es siempre **en efectivo** y queda ligado a su turno abierto (afecta el corte); el **admin-sucursal** registra sin turno, con `payment_method` opcional (cash/card/transfer, vacío = sin especificar) y sin atar el gasto a un turno. Desde 2026-07-14 el alta delega en **`ExpenseWriter`** (el mismo servicio de dominio que `Sucursal\GastoController`): creación + adjuntos del borrador IA + consumo del draft ocurren en una sola transacción y con las mismas reglas que la web, sin lógica duplicada.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `expenses?search=&from=&to=` | Cajero: sus gastos; admin: toda la sucursal. Incluye `can_manage` por fila (cajero: solo gastos del turno abierto), árbol de categorías, `payment_methods`, `total` filtrado exacto, `meta` de paginación y contexto del turno |
| POST | `expenses` | Crea gasto (`concept`, `amount`, `expense_subcategory_id`, `description`, `payment_method` solo admin, `ai_draft_id` opcional). `409` sin turno abierto (solo cajero) |
| POST | `expenses/ai-draft` | Borrador por IA (texto/imagen/audio → GPT-4o/Whisper, síncrono). Devuelve `draft_id` + `proposal` para prerrellenar; el gasto se crea al confirmar con `store` pasando `ai_draft_id` (mueve la foto del ticket al gasto). `502` si la IA falla |
| GET | `expenses/{id}` | Detalle (cajero: solo propios; admin: de la sucursal) |
| PATCH | `expenses/{id}` | Edita. Cajero: `403` si el gasto no es de su turno abierto ("Solo puedes corregir tus gastos del turno abierto"). `422` si está cancelado |
| DELETE | `expenses/{id}` | Cancela (soft, `cancellation_reason` opcional). Misma regla de turno que PATCH. `422` si ya estaba cancelado |
| POST | `expenses/{id}/attachments` | Adjunta archivos (jpg/png/webp/pdf) |
| GET | `expenses/{id}/attachments/{aid}` | Descarga el adjunto |
| DELETE | `expenses/{id}/attachments/{aid}` | Elimina el adjunto |

## Compras (requiere toggle `cashier_purchases_enabled`)

> 2026-07-13: el índice acepta `q` (folio/factura/proveedor), `provider_id` y `payment_status` (pending/partial/paid), excluye canceladas (paridad `applyIndexFilters` web) y devuelve `kpis` del conjunto filtrado (`total_amount`, `count`, `pending_total`, `pending_count`). El `show` carga `history` (timeline AuditLog) en `HubPurchaseResource`.

**Controller:** `Api\Hub\PurchaseController` (reusa el trait `HandlesPurchases` de la web).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `purchases` | Compras propias del usuario (máx. 50) + proveedores activos + métodos de pago |
| POST | `purchases` | Crea compra con items; el `branch_id` lo fija el turno (`409` sin turno). `paid_amount` opcional registra un pago inicial en efectivo contra el turno |
| POST | `purchases/ai-draft` | Borrador por IA de la factura (mismo pipeline que gastos). `502` si falla |
| GET | `purchases/{id}` | Detalle con proveedor, items, pagos y adjuntos |
| PATCH | `purchases/{id}` | Edita (reemplaza items y recalcula) |
| POST | `purchases/{id}/cancel` | Cancela la compra |
| POST | `purchases/{id}/payments` | Pago a la compra (`amount`, `payment_method`, `reference`, `notes`). Si es `cash` exige turno abierto (`409`) y se ata a él. `422` si sobre-paga |
| DELETE | `purchases/{id}/payments/{pid}` | Cancela un pago (body: `reason`) |
| POST/GET/DELETE | `purchases/{id}/attachments[/{aid}]` | Adjuntos (igual que en gastos) |

## Proveedores (solo admin-sucursal)

**Controller:** `Api\Hub\ProviderController`. Catálogo **tenant-wide** (compartido entre sucursales). Lectura siempre; **crear/editar solo si la empresa habilitó `branch_admin_providers_enabled`** en la sucursal (`403` si no). **No hay borrado** desde el hub (queda en admin-empresa web).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `providers?q=&type=` | Lista con búsqueda y filtro por tipo. Incluye `can_manage` y `types`. Con `can_manage` también muestra inactivos |
| POST | `providers` | Crea proveedor (requiere toggle) |
| PUT | `providers/{id}` | Edita, incluye `status` (requiere toggle; cross-tenant → 404) |
| GET | `providers/{id}` | Detalle + `resumen` (compras, comprado, pagado, deuda, última compra) **scopeado a la sucursal** |
| GET | `providers/{id}/compras` | Compras al proveedor en la sucursal, paginadas (20) |
| GET | `providers/{id}/pagos` | Pagos al proveedor en la sucursal, paginados (20) |
| GET | `providers/{id}/productos` | Agregado por concepto/unidad de lo comprado (top 100 por importe) |
| POST | `providers/{id}/pagos` | **Pago a cuenta**: FIFO sobre las compras pendientes del proveedor en la sucursal (`amount`, `payment_method`, `reference`, `notes`). `201` con `applied_count`. En efectivo exige turno abierto (`409`) y ata cada pago generado a ese turno |

## Tiempo real (Reverb/Echo)

**Controller:** `Api\Hub\RealtimeController`. Permite al hub suscribirse al mismo canal privado que usa la web.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `realtime/config` | Parámetros de conexión a Reverb: `key` (pública), `host`, `port`, `scheme`. Equivale a las `VITE_REVERB_*` de la web |
| POST | `realtime/auth` | Autoriza la suscripción a un canal privado (`Broadcast::auth`). Es el reemplazo de `/broadcasting/auth` para clientes con token Bearer (sin sesión/CSRF) |

El canal `sucursal.{branchId}` (`routes/channels.php`) autoriza con el guard por defecto de la ruta: `web` en Inertia, **`sanctum` en el hub** (la ruta corre tras `auth:sanctum`). La regla es la misma: `user->branch_id === branchId`. Los controllers del hub disparan los broadcasts de forma tolerante: si Reverb está caído, la operación no falla (solo se loguea un warning).

Por ese canal el hub recibe:

| Evento | Lo consume | Para qué |
|--------|-----------|----------|
| `NewExternalSale` | Mesa de Trabajo | Venta nueva de báscula o menú QR (suena el beep) |
| `SaleUpdated` | Mesa de Trabajo · panel de Turno | Cualquier cambio en una venta existente |
| `SaleLocked` · `SaleUnlocked` | Mesa de Trabajo | Bloqueo cooperativo de edición |
| `CustomerGlobalPaymentChanged` | Mesa de Trabajo · panel de Turno | Un cobro global FIFO tocó N ventas de una vez |
| `ShiftUpdated` | Panel de Turno | Apertura, cierre o retiro |

> Los payloads llevan **identificadores, no cifras**: quien recibe vuelve a leer por HTTP con su propio token. `ShiftUpdated` en particular viaja por un canal que comparten todos los usuarios de la sucursal, y `shift/current` sólo devuelve el turno del usuario autenticado.

El socket es el mecanismo principal, pero el hub conserva su sondeo como red de seguridad: 20 s / 4 s en la Mesa de Trabajo y 45 s / 12 s en el panel de Turno, según haya socket o no. Ver [arquitectura/reverb-websockets.md](../arquitectura/reverb-websockets.md).

## Equipos (latido)

| Método | Ruta | Rol | Descripción |
|--------|------|-----|-------------|
| POST | `devices/heartbeat` | ambos | El hub se reporta a sí mismo y reenvía el latido de las básculas emparejadas que no tienen credenciales de nube |

Mismo body y misma respuesta que `POST /api/v1/devices/heartbeat` de la Scale API (ver [endpoints.md](endpoints.md#post-apiv1devicesheartbeat)); cambia quién lo firma: aquí la sucursal sale del usuario Sanctum y el equipo queda marcado con `via = hub`. Un hub reenvía el de cada báscula con el `device_id` y `kind` de esa báscula, no los suyos. Añadido el 2026-09-16. Módulo: [equipos.md](../modulos/equipos.md).

## Códigos de error comunes

| Código | Causa típica |
|--------|--------------|
| `401` | Token Sanctum ausente/inválido |
| `403` | Rol sin acceso al hub, endpoint solo admin-sucursal, o módulo/toggle deshabilitado en la sucursal |
| `404` | Recurso de otra sucursal/tenant (el scoping devuelve not found, no forbidden) |
| `409` | Sin turno abierto, turno ya abierto, lock de venta tomado por otro usuario, o `force_password_change` en login |
| `422` | Validación, transición de estado inválida, sobre-pago, o venta ya cobrada/cancelada |
| `502` | Fallo del pipeline de IA (ai-draft) |

## Referencias

- Rutas: `routes/api.php` (grupos `v1/auth` y `v1/hub`) · canal: `routes/channels.php`
- Middleware: `app/Http/Middleware/EnsureHubRole.php` (alias `hub.role`)
- Controllers: `app/Http/Controllers/Api/Hub/`
- Tests: `tests/Feature/Api/Hub/` (13 archivos, uno por dominio)
- Specs de diseño: `docs/superpowers/specs/2026-05-29-hub-login-flow-design.md`, `2026-06-01-hub-migracion-modulos-fase1-design.md`, `2026-06-16-hub-proveedores-design.md`
- API de básculas (comparativa): `docs/api/autenticacion-apikey.md`, `docs/api/endpoints.md`
