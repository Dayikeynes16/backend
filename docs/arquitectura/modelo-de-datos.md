# Modelo de datos

Mapa del esquema: 46 tablas repartidas en siete dominios, sobre PostgreSQL 18. Este documento explica **cómo está organizado y por qué**, no cada columna — para eso está la migración de cada tabla, y `sail artisan db:table {tabla}`.

## Responsabilidades

- Dar una vista de conjunto de las tablas y a qué dominio pertenece cada una.
- Documentar las convenciones que se repiten en todo el esquema.

**No hace:** no sustituye a las migraciones, que son la fuente de verdad. No documenta índices ni columnas una por una.

## Las convenciones, primero

Entender estas cinco reglas ahorra la mitad del esquema.

### 1. Aislamiento por columna, no por base de datos

Las tablas de negocio llevan **`tenant_id`**, y su modelo usa el trait `BelongsToTenant`, que arranca `TenantScope`: filtra toda consulta y rellena la columna al crear. Son **28 modelos**.

Dos modelos deliberadamente **no** lo llevan — `CashWithdrawal` y `CustomerProductPrice` —: se alcanzan siempre a través de su padre (el turno y el cliente), que sí está filtrado.

Ver [multitenant.md](multitenant.md).

### 2. `branch_id` es el segundo eje

Catorce tablas llevan además **`branch_id`**. El tenant separa empresas; la sucursal separa la operación dentro de una empresa. También es lo que autoriza el canal de tiempo real `sucursal.{branchId}` y lo que acota una API Key de báscula.

### 3. El dinero es `decimal`, nunca flotante

| Uso | Tipo |
|-----|------|
| Totales, subtotales, montos, saldos | `decimal(12, 2)` |
| Precios de producto | `decimal(10, 2)` |
| Precio unitario de compra | `decimal(12, 4)` — el insumo se compra a fracciones de centavo |
| Cantidades (peso) | `decimal(10, 3)` — gramos |

Los saldos derivados (`amount_paid`, `amount_pending`) **se escriben solo desde su servicio de dominio**, nunca a mano. Ver [CONTRIBUTING.md](../../CONTRIBUTING.md).

### 4. Las líneas guardan una foto, no una referencia

`sale_items` no solo apunta al producto: **copia** `product_name`, `unit_type` y `unit_price` en el momento de la venta. `product_id` es `nullOnDelete`.

Es deliberado: una venta de hace seis meses debe seguir mostrando lo que se cobró, aunque el producto se haya renombrado, cambiado de precio o borrado. Lo mismo aplica en `purchase_items`.

### 5. Idempotencia donde se reintenta

`sales.client_reference` con índice único `(branch_id, client_reference)`: si llega dos veces la misma referencia, se devuelve la venta existente en vez de duplicarla. Es lo que hace seguro el reintento del outbox del hub. Los pagos del hub siguen la misma regla.

## Los siete dominios

### Núcleo organizativo

| Tabla | Qué guarda |
|-------|------------|
| `tenants` | Las empresas. Raíz de todo el aislamiento. Lleva el cupo `max_users` y el presupuesto mensual de IA |
| `branches` | Sucursales. **Aquí viven los 8 feature flags por sucursal** |
| `users` | Usuarios, con `tenant_id` y `branch_id` (nulos para superadmin) |
| `settings` | Configuración por tenant |
| `api_keys` | Llaves de báscula, hash SHA-256, acotadas a una sucursal |
| `password_reset_logs` | Bitácora de restablecimientos |

Roles y permisos viven en las tablas de Spatie (`roles`, `model_has_roles`), fuera de esta cuenta.

### Venta

| Tabla | Qué guarda |
|-------|------------|
| `sales` | La venta: estado, folio por sucursal, origen, totales, `client_reference` |
| `sale_items` | Líneas, con la foto del producto |
| `sale_item_changes` | Bitácora de ediciones de línea, con su efecto en dinero |
| `payments` | Cobros de una venta, por método |
| `payment_receipts` | Comprobantes de transferencia adjuntos |
| `products`, `categories`, `product_presentations` | Catálogo de venta |

El ciclo de vida de `sales.status` es una máquina de estados explícita: ver [modulos/ventas.md](../modulos/ventas.md).

### Caja

| Tabla | Qué guarda |
|-------|------------|
| `cash_register_shifts` | Turnos: apertura, cierre y conciliación por método |
| `cash_withdrawals` | Retiros de efectivo durante el turno |

### Clientes y fiado

| Tabla | Qué guarda |
|-------|------------|
| `customers` | Clientes. Teléfono normalizado a E.164; `name_pending` marca los creados sin nombre |
| `customer_payments` | Cobros globales, que se reparten FIFO entre las ventas pendientes |
| `customer_product_prices` | Precios preferenciales por cliente y producto |

### Gastos

| Tabla | Qué guarda |
|-------|------------|
| `expenses` | Gastos operativos (OPEX) |
| `expense_categories`, `expense_subcategories` | Catálogo, con alias para la captura por IA |
| `expense_attachments` | Tickets y facturas, en disco privado |

### Compras y proveedores

| Tabla | Qué guarda |
|-------|------------|
| `purchases` | Compras (CMV), folio `CMP-YYYY-NNNNN`, con su cuenta por pagar |
| `purchase_items` | Líneas de compra |
| `purchase_products`, `purchase_product_categories` | Catálogo de insumos, **tenant-wide** (no por sucursal) |
| `providers` | Proveedores, también tenant-wide |
| `provider_payments` | Pagos a cuenta, repartidos FIFO |
| `purchase_attachments` | Facturas de compra |

> **Compras y Gastos están separados a propósito**: costo de mercancía frente a gasto operativo. No hay inventario todavía, por diseño.

### IA, agenda y auditoría

| Tabla | Qué guarda |
|-------|------------|
| `ai_assistant_sessions`, `ai_assistant_messages` | Conversaciones del asistente |
| `assistant_drafts` | Borradores de escritura del asistente, de un solo uso |
| `ai_expense_drafts`, `ai_purchase_drafts`, `ai_category_drafts` | Borradores de la captura por formulario |
| `agenda_items` | Pendientes y recordatorios, con recurrencia |
| `audit_logs` | Bitácora de cambios sobre entidades del negocio |

**Ninguna escritura de la IA toca las tablas de negocio directamente**: pasa por un borrador que un humano confirma, y esa confirmación revalida todo en el servidor. Ver [modulos/asistente-ia.md](../modulos/asistente-ia.md).

### Infraestructura de Laravel

`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`, `personal_access_tokens` (los tokens Sanctum del hub).

## Cómo se relacionan

```
tenants ──┬── branches ──┬── users
          │              ├── products ── product_presentations
          │              ├── categories
          │              ├── api_keys
          │              ├── cash_register_shifts ── cash_withdrawals
          │              ├── customers ──┬── customer_payments
          │              │               └── customer_product_prices
          │              └── sales ──┬── sale_items ── sale_item_changes
          │                          └── payments ── payment_receipts
          ├── providers ── provider_payments
          ├── purchase_products ── purchase_product_categories
          ├── purchases ──┬── purchase_items
          │               └── purchase_attachments
          ├── expense_categories ── expense_subcategories
          ├── expenses ── expense_attachments
          ├── agenda_items
          └── audit_logs
```

Una venta pertenece a una sucursal y, opcionalmente, a un cliente y al usuario que la cobró.

## Trabajar con el esquema

```bash
sail artisan db:table sales          # columnas e índices de una tabla
sail artisan db:show                 # todas las tablas con su tamaño
sail artisan migrate:status          # qué migraciones corrieron
sail artisan migrate:fresh --seed    # empezar de cero con datos demo
```

**Al añadir una migración:** nunca edites una ya publicada, agrega otra. Si la tabla es de negocio, casi seguro necesita `tenant_id` y el trait `BelongsToTenant` en su modelo — y su factory y su seeder.

## Fuera de esta base

El hub de escritorio tiene **su propia base SQLite local** con la cola de ventas sin sincronizar. No comparte esquema con esta: ver `carniceria-hub/docs/base-de-datos-local.md`.
