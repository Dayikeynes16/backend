# Clientes con Precios Preferenciales

**Fecha:** 2026-04-12
**Status:** Aprobado
**Scope:** Módulo nuevo — CRUD de clientes por sucursal, precios fijos preferenciales por producto, asignación de cliente a ventas existentes con recálculo automático.

---

## Decisiones de diseño

- Clientes son **por sucursal** (cada branch maneja su propio catálogo)
- Precios preferenciales son **precio fijo** por producto (no porcentuales)
- El cliente se asigna en el **panel derecho del Workbench** sobre una venta ya creada (no en el modal de nueva venta)
- Si la venta ya tiene pagos, se recalcula el pendiente sin eliminar pagos existentes, con advertencia
- Solo **administradores** (admin-sucursal, admin-empresa, superadmin) pueden gestionar clientes y asignarlos a ventas
- El cajero no ve ni interactúa con clientes

---

## Modelo de datos

### Tabla `customers`

| Columna    | Tipo              | Notas                              |
|------------|-------------------|------------------------------------|
| id         | bigint PK         |                                    |
| tenant_id  | FK → tenants      | BelongsToTenant trait              |
| branch_id  | FK → branches     | Scope por sucursal                 |
| name       | string(255)       | Requerido                          |
| phone      | string(20)        | Requerido, unique por branch       |
| notes      | text nullable     | Observaciones opcionales           |
| status     | string default 'active' | active / inactive            |
| timestamps |                   |                                    |

Constraint: `unique(['phone', 'branch_id'])`

### Tabla `customer_product_prices`

| Columna     | Tipo           | Notas                          |
|-------------|----------------|--------------------------------|
| id          | bigint PK      |                                |
| customer_id | FK → customers | cascade delete                 |
| product_id  | FK → products  | cascade delete                 |
| price       | decimal(12,2)  | Precio fijo preferencial       |
| timestamps  |                |                                |

Constraint: `unique(['customer_id', 'product_id'])`

### Columna nueva en `sales`

| Columna     | Tipo                    | Notas        |
|-------------|-------------------------|--------------|
| customer_id | FK nullable → customers | nullOnDelete |

### Relaciones Eloquent

- `Customer` → `BelongsToTenant` trait, `belongsTo(Branch)`, `hasMany(CustomerProductPrice)`, `hasMany(Sale)`
- `CustomerProductPrice` → `belongsTo(Customer)`, `belongsTo(Product)`
- `Sale` → `belongsTo(Customer)` (nullable)

### Snapshot de precios

Los campos existentes `sale_items.unit_price` y `sale_items.subtotal` actúan como snapshot. Se escriben al asignar/desasignar cliente y no cambian si después se modifican los precios preferenciales.

---

## Endpoints

### CustomerController (`Sucursal/CustomerController`)

| Método | Ruta                              | Acción                                        |
|--------|-----------------------------------|-----------------------------------------------|
| GET    | `sucursal/clientes`               | index — listado con búsqueda y paginación     |
| POST   | `sucursal/clientes`               | store — crear cliente                         |
| PUT    | `sucursal/clientes/{customer}`    | update — editar nombre/teléfono/notas/status  |
| DELETE | `sucursal/clientes/{customer}`    | destroy — eliminar o inactivar si tiene ventas|

### CustomerPriceController (`Sucursal/CustomerPriceController`)

| Método | Ruta                                                  | Acción                               |
|--------|-------------------------------------------------------|--------------------------------------|
| POST   | `sucursal/clientes/{customer}/precios`                | store — asignar precio a producto    |
| PUT    | `sucursal/clientes/{customer}/precios/{price}`        | update — modificar precio            |
| DELETE | `sucursal/clientes/{customer}/precios/{price}`        | destroy — eliminar (vuelve estándar) |

### Asignación en venta (WorkbenchController)

| Método | Ruta                                              | Acción                            |
|--------|---------------------------------------------------|-----------------------------------|
| PATCH  | `sucursal/mesa-de-trabajo/ventas/{sale}/cliente`  | assignCustomer — asignar/desasignar|

Middleware: `role:admin-sucursal|superadmin` (consistente con el resto del Workbench).

---

## Lógica de `assignCustomer`

1. Recibe `customer_id` (nullable — null para desasignar)
2. Valida que `customer.branch_id === sale.branch_id`
3. Valida que `sale.status !== cancelled`
4. Dentro de transacción con `pg_advisory_xact_lock($branchId)`:
   - Si asignando: obtiene `customer_product_prices` del cliente, recorre cada `SaleItem`, si existe precio preferencial para ese `product_id` → actualiza `unit_price` y `subtotal`
   - Si desasignando: recorre cada `SaleItem`, restaura `unit_price` al precio actual del producto en BD, recalcula `subtotal`
5. Recalcula `sale.total` = suma de subtotales
6. Recalcula `sale.amount_pending = total - amount_paid`
7. Si `amount_pending <= 0` y hay pagos → marca como completed
8. Guarda `sale.customer_id`
9. Flash warning si la venta tenía pagos

---

## Frontend

### Vista `Sucursal/Clientes/Index.vue`

Dos paneles (mismo patrón que Historial/Workbench):

**Panel izquierdo:**
- Búsqueda por nombre o teléfono
- Filtro por status (Activos / Inactivos / Todos)
- Botón "Nuevo Cliente"
- Cards con nombre, teléfono, badge status

**Panel derecho (cliente seleccionado):**
- Header: nombre, teléfono, notas, botones Editar/Eliminar
- Sección "Precios preferenciales":
  - Tabla: Producto | Precio estándar | Precio preferencial | Acciones
  - Botón "Agregar precio" → selector de productos activos sin precio asignado
  - Input inline para precio
  - Eliminar por fila
  - Visual: verde si precio < estándar, rojo con warning si precio > estándar

**Modal "Nuevo Cliente":**
- Campos: Nombre (req), Teléfono (req), Notas (opt)
- Validación unique de teléfono por branch

### Workbench — Bloque de asignación de cliente

Ubicación: panel derecho, debajo del header, antes de la tabla de productos. Solo visible para roles admin (`canCreate`).

**Sin cliente:**
- Botón "Asignar cliente"
- Popover de búsqueda inline (nombre/teléfono), resultados filtrados del prop `customers` (id, name, phone)
- Al seleccionar → PATCH assignCustomer → refresh

**Con cliente:**
- Badge con nombre y teléfono
- Indicador "Precio preferencial" en items de la tabla que tengan precio modificado
- Botón "x" para desasignar → PATCH con customer_id: null → restaura precios
- Warning previo si hay pagos

**Props adicionales en WorkbenchController::index():**
- `customers`: lista ligera de clientes activos de la branch (id, name, phone)
- Sale with `customer:id,name,phone` en el eager load existente

---

## Seguridad y robustez

**Validaciones:**
- Backend siempre verifica `customer.branch_id === sale.branch_id`
- Teléfono unique por branch (compound unique en migración)
- Eliminar cliente con ventas → inactivar, no borrar. `nullOnDelete` como safety net

**Snapshot de precios:**
- Precios se graban en `sale_items` al asignar — cambios posteriores en precios preferenciales no afectan ventas existentes
- Productos eliminados limpian `customer_product_prices` via cascade, ventas históricas conservan `product_name` y `unit_price`

**Concurrencia:**
- `assignCustomer` usa `pg_advisory_xact_lock($branchId)` en transacción

**Edge cases:**
- Asignar cliente a venta cancelada → bloqueado
- Cliente sin precios preferenciales → se guarda relación, precios no cambian
- Desasignar → items vuelven al precio actual del producto en BD
- Pagos exceden nuevo total → `amount_pending = 0`, se marca completed
