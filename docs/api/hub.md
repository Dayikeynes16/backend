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
        "tenant_id": 1, "tenant_slug": "el-toro"
    }
}
```

**Errores:** `401` credenciales incorrectas · `403` el usuario no tiene rol de hub · `409` el usuario tiene `force_password_change` pendiente (debe cambiarla en la web).

## Roles y scoping

- **Middleware `hub.role`** (`App\Http\Middleware\EnsureHubRole`): todo `/api/v1/hub/*` exige rol `cajero` o `admin-sucursal`. Otros roles → `403`. `admin-empresa` y `superadmin` operan solo por web.
- **Scoping por sucursal:** los controllers del hub no usan `ResolveTenant` (no hay slug en la URL). Resuelven los recursos con `withoutGlobalScopes()` filtrando por el `branch_id` del usuario del token; un recurso de otra sucursal devuelve `404`. Para modelos con `TenantScope` que se reusan de la web (ApiKey, Provider, Expense…), el controller fija `app()->instance('tenant', $user->tenant)`.
- **Endpoints solo admin-sucursal** (el cajero recibe `403`): toda la sección Config, toda la sección Proveedores y la cancelación de cobros globales de fiado.
- **Toggles por sucursal:** Gastos requiere `cashier_expenses_enabled`, Compras `cashier_purchases_enabled`, y crear/editar proveedores `branch_admin_providers_enabled` (todos en `branches`; si están apagados → `403`).

## Dashboard

| Método | Ruta | Rol | Descripción |
|--------|------|-----|-------------|
| GET | `dashboard` | ambos | KPIs del día de la sucursal |

`Api\Hub\DashboardController` devuelve: `today` (ventas del día no canceladas: conteo, total, pendiente, gastos, cobrado), `by_method` (cobranza por cash/card/transfer), `recent_sales` (8 últimas), `top_products` (top 5 por importe) y `shift` (resumen de conciliación en vivo del turno abierto vía `ShiftService::summary`, o `null`).

## Configuración de sucursal (solo admin-sucursal)

**Controller:** `Api\Hub\ConfigController`. Configuración de *negocio* (la config técnica del hub — puerto, backend URL — vive en el proceso main de Electron).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `config` | Datos de la sucursal + métodos de pago habilitados + lista de API keys |
| PUT | `config/payment-methods` | Actualiza `payment_methods_enabled` (body: `payment_methods[]`, mín. 1, subconjunto de los soportados) |
| POST | `config/api-keys` | Genera una API key de báscula (body: `name`, `expires_in_days` opcional 1–365). Devuelve `raw_key` **una sola vez** (para el QR de vinculación); solo se persiste el hash |
| DELETE | `config/api-keys/{id}` | Revoca (marca `inactive`) |
| DELETE | `config/api-keys/{id}/force` | Elimina definitivamente. `422` si sigue activa y no expirada (hay que revocar primero) |

## Turno (caja)

**Controller:** `Api\Hub\ShiftController` (reusa `ShiftService`).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `shift/current` | Turno abierto del usuario (`data: null` si no hay) + `summary` de conciliación en vivo (esperado, totales por método, salidas) |
| POST | `shift/open` | Abre turno (body: `opening_amount` opcional). `201`, o `409` si ya tiene uno abierto |
| POST | `shift/close` | Cierra turno (body: `declared_amount`, `declared_card`, `declared_transfer`, `notes`, todos opcionales). Devuelve el corte: shift cerrado + `summary` |

El turno abierto es requisito para cobrar ventas, registrar gastos, registrar compras y pagos en efectivo a proveedores (`409` si no hay).

## Ventas y cobros

**Controllers:** `Api\Hub\SaleController` + `Api\Hub\PaymentController`. Las ventas llegan de las básculas (API `X-Api-Key`); el hub las cobra.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `sales?status=active\|pending\|all` | Ventas activas/pendientes de la sucursal (máx. 50) + `counts` por estado |
| GET | `sales/{id}` | Detalle con items/pagos/cliente. Incluye `payment_methods` habilitados de la sucursal y datos de `branch` (para el ticket) |
| POST | `sales/{id}/payments` | Registra un pago (ver contrato abajo) |
| PATCH | `sales/{id}/status` | Pausar/reactivar: el cajero solo puede transicionar `active` ↔ `pending` (`403` otra transición, `422` si el estado no lo permite) |
| POST | `sales/{id}/request-cancel` | Solicita cancelación (body: `cancel_request_reason`, la aprueba un admin en la web). `422` si ya está cancelada o ya hay solicitud |
| PATCH | `sales/{id}/customer` | Asigna/desasigna cliente (`customer_id` o `null`) y aplica precios preferenciales (reusa `AssignCustomerToSale`) |
| GET | `sales/{id}/whatsapp` | Link `wa.me` del ticket; `reason=needs_phone` si la venta no tiene teléfono |
| POST | `sales/{id}/whatsapp-phone` | Guarda `contact_phone` (10 dígitos → E.164) y devuelve el link. No crea cliente |
| POST | `sales/{id}/lock` | Adquiere el lock de concurrencia (5 min). `409` con `locked_by_name` si otro usuario lo tiene. Adquirir uno libera los locks previos del usuario |
| POST | `sales/{id}/unlock` | Libera el lock (solo si es propio) |
| POST | `sales/{id}/heartbeat` | Renueva `locked_at` (mantiene vivo el lock) |

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
| GET | `history` | ambos | Ventas donde el usuario del token registró al menos un pago |

`Api\Hub\HistoryController`. Query params: `date` (default hoy), `product` (búsqueda en items), `min_total`, `max_total`. Paginado (20) + `summary` (`count`, `total`) sobre todo el conjunto filtrado. Misma semántica que el historial web de caja.

## Clientes y fiado

**Controllers:** `Api\Hub\CustomerController`, `CustomerPaymentController`, `CustomerPriceController`.

| Método | Ruta | Rol | Descripción |
|--------|------|-----|-------------|
| GET | `customers` | ambos | Lista (máx. 200) con deuda/compras agregadas + `summary` de cartera. Filtros: `search` (nombre/teléfono), `status`, `with_debt`, `sort=name\|debt\|last_sale` |
| POST | `customers` | ambos | Alta (`name`, `phone` único por sucursal, `notes`) |
| GET | `customers/{id}` | ambos | Detalle + `stats` (gastado, pagado, deuda, ticket promedio, producto top) + precios preferenciales |
| PATCH | `customers/{id}` | ambos | Edición (incluye `status`) |
| DELETE | `customers/{id}` | ambos | Si tiene ventas → desactiva (`action: "deactivated"`); si no → borra |
| GET | `customers/{id}/history` | ambos | Compras del cliente paginadas (25), no canceladas |
| GET | `customers/{id}/payments` | ambos | Ledger de fiado: ventas pendientes + últimos 30 cobros globales + `total_owed` + métodos de pago |
| POST | `customers/{id}/payments` | ambos | **Cobro global FIFO** (ver abajo) |
| DELETE | `customers/{id}/payments/{pid}` | **admin-sucursal** | Cancela un cobro global (body: `cancel_reason`): borra los pagos hijos, recalcula ventas y turnos cerrados afectados |
| POST | `customers/{id}/prices` | ambos | Precio preferencial (`product_id`, `price`); único por producto |
| PATCH | `customers/{id}/prices/{pid}` | ambos | Actualiza `price` |
| DELETE | `customers/{id}/prices/{pid}` | ambos | Elimina el precio |

**Cobro global (`POST customers/{id}/payments`):** requiere turno abierto (`409`). Body: `amount_received`, `method`, `excluded_sale_ids[]` opcional, `notes`. Distribuye el abono FIFO (venta más antigua primero) sobre las ventas con saldo del cliente, creando un `Payment` por venta ligado a un `CustomerPayment` con folio `CG-00001`. Solo `cash` admite cambio; con otros métodos el monto no puede exceder la deuda (`422`). Responde `201` con el `customer_payment` y el detalle `applied` por venta. Usa advisory lock de PostgreSQL por sucursal para evitar cobros concurrentes.

## Productos (apoyo)

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `products?search=` | Catálogo activo de la sucursal (`id`, `name`, `price`, `unit_type`; máx. 50). Apoyo de formularios (p. ej. precios preferenciales) |
| GET | `purchase-products?search=` | Catálogo tenant-wide de productos de compra (`id`, `name`, `unit`) para autocompletar el formulario de compras |

## Gastos (cajero, requiere toggle `cashier_expenses_enabled`)

**Controller:** `Api\Hub\ExpenseController`. Los gastos del hub son siempre **en efectivo** y quedan ligados al turno abierto (afectan el corte).

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `expenses?search=` | Gastos propios del usuario (máx. 50, no cancelados) + árbol de categorías/subcategorías activas + `total` filtrado + contexto del turno |
| POST | `expenses` | Crea gasto (`concept`, `amount`, `expense_subcategory_id`, `description`, `ai_draft_id` opcional). `409` sin turno abierto |
| POST | `expenses/ai-draft` | Borrador por IA (texto/imagen/audio → GPT-4o/Whisper, síncrono). Devuelve `draft_id` + `proposal` para prerrellenar; el gasto se crea al confirmar con `store` pasando `ai_draft_id` (mueve la foto del ticket al gasto). `502` si la IA falla |
| GET | `expenses/{id}` | Detalle (solo gastos propios) |
| PATCH | `expenses/{id}` | Edita. `422` si está cancelado |
| DELETE | `expenses/{id}` | Cancela (soft, `cancellation_reason` opcional). `422` si ya estaba cancelado |
| POST | `expenses/{id}/attachments` | Adjunta archivos (jpg/png/webp/pdf) |
| GET | `expenses/{id}/attachments/{aid}` | Descarga el adjunto |
| DELETE | `expenses/{id}/attachments/{aid}` | Elimina el adjunto |

## Compras (cajero, requiere toggle `cashier_purchases_enabled`)

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
| POST | `providers/{id}/pagos` | **Pago a cuenta**: FIFO sobre las compras pendientes del proveedor en la sucursal (`amount`, `payment_method`, `reference`, `notes`). `201` con `applied_count` |

## Tiempo real (Reverb/Echo)

**Controller:** `Api\Hub\RealtimeController`. Permite al hub suscribirse al mismo canal privado que usa la web.

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `realtime/config` | Parámetros de conexión a Reverb: `key` (pública), `host`, `port`, `scheme`. Equivale a las `VITE_REVERB_*` de la web |
| POST | `realtime/auth` | Autoriza la suscripción a un canal privado (`Broadcast::auth`). Es el reemplazo de `/broadcasting/auth` para clientes con token Bearer (sin sesión/CSRF) |

El canal `sucursal.{branchId}` (`routes/channels.php`) autoriza con el guard por defecto de la ruta: `web` en Inertia, **`sanctum` en el hub** (la ruta corre tras `auth:sanctum`). La regla es la misma: `user->branch_id === branchId`. Por ese canal el hub recibe `NewExternalSale`, `SaleLocked`, `SaleUnlocked` y `SaleUpdated`. Los controllers del hub disparan los broadcasts de forma tolerante: si Reverb está caído, la operación no falla (solo se loguea un warning).

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
