# Flujo de Ventas y Pagos — Spec de Diseño

**Fecha:** 2026-04-01
**Enfoque seleccionado:** B — Chips en Mesa de Trabajo + Sección de Pagos separada

---

## Problema

1. Mesa de Trabajo mezcla ventas activas y pendientes sin filtros.
2. El cajero no puede pausar ni reactivar ventas — depende del admin para algo operacional.
3. No existe visibilidad de pagos como entidad independiente. Para saber "cuánto cobré en tarjeta hoy" hay que revisar venta por venta o ir a Cortes.

## Alcance

Tres cambios independientes que se complementan:

1. **Filtros chip en Mesa de Trabajo** (frontend)
2. **Cajero puede pausar/reactivar** (frontend + backend)
3. **Nueva sección "Pagos"** (frontend + backend)

---

## 1. Filtros chip en Mesa de Trabajo

### Comportamiento

Barra de chips arriba de la lista de ventas en el workbench (Sucursal y Caja).

| Chip           | Filtro                 | Default |
| -------------- | ---------------------- | ------- |
| Activas (N)    | `status === 'active'`  | Si      |
| Pendientes (N) | `status === 'pending'` | No      |
| Todas (N)      | sin filtro             | No      |

- `N` = conteo de ventas en cada estado.
- Filtro puramente frontend: un `ref('active')` que filtra el array `sales` con `computed`.
- El backend sigue cargando Active + Pending (sin cambio en queries).
- Estilo visual: mismo patrón de chips del Historial (pill, color activo en rojo).

### Archivos a modificar

- `resources/js/Pages/Sucursal/Workbench.vue`
- `resources/js/Pages/Caja/Workbench.vue`

---

## 2. Cajero puede pausar y reactivar

### Comportamiento

El cajero puede cambiar estado Active <-> Pending desde su Mesa de Trabajo.

**Menú contextual del cajero mostrará:**

- Venta activa: "Pausar" (Active -> Pending)
- Venta pendiente: "Reactivar" (Pending -> Active)
- "Solicitar cancelación" (como ya existe)

**NO mostrará:** Reabrir, Cancelar directo.

### Frontend

`SaleContextMenu.vue` — refactor del prop `canManageStatus` (booleano) a una lista de acciones permitidas para mayor granularidad.

Ejemplo:

```
// Admin-sucursal
:allowed-actions="['pause', 'reactivate', 'reopen', 'cancel']"

// Cajero
:allowed-actions="['pause', 'reactivate', 'request-cancel']"
```

### Backend

**Nueva ruta:**

```
PATCH /{tenant}/caja/ventas/{sale}/estado
```

**Controller:** `Caja\WorkbenchController@updateStatus`

- Solo permite transiciones Active <-> Pending.
- Valida con `SaleStatus::canTransitionTo()`.
- Valida lock (no puede pausar si otro usuario tiene la venta locked).
- Valida `branch_id` y tenant scope.

### Archivos a modificar/crear

- `resources/js/Components/SaleContextMenu.vue` — refactor props
- `resources/js/Pages/Caja/Workbench.vue` — pasar acciones permitidas
- `resources/js/Pages/Sucursal/Workbench.vue` — adaptar al nuevo prop
- `app/Http/Controllers/Caja/WorkbenchController.php` — agregar `updateStatus`
- `routes/web.php` — agregar ruta

### Seguridad

- Backend restringe transiciones para cajero (solo active/pending), no solo frontend.
- Lock check antes de cambiar estado.

---

## 3. Nueva sección "Pagos"

### Propósito

Vista de consulta/auditoría de cobros realizados. Solo lectura, sin acciones de edición.

### Acceso por rol

| Rol            | Scope de datos                            | Filtro por cajero         |
| -------------- | ----------------------------------------- | ------------------------- |
| Admin-sucursal | Todos los pagos de la sucursal            | Si (dropdown de usuarios) |
| Cajero         | Solo pagos donde `user_id = auth()->id()` | No (forzado en backend)   |

### Layout

Two-pane (mismo patrón que Historial).

### Panel izquierdo — Lista de pagos

**Filtros (barra superior):**

- Chips de método: Todos (default) | Efectivo | Tarjeta | Transferencia
- DatePicker: default "hoy", permite cambiar fecha/rango
- Filtro por cajero: solo visible para admin-sucursal, dropdown con usuarios de la sucursal

**Resumen de totales (debajo de filtros):**

- Total cobrado (suma de pagos visibles según filtros)
- Desglose: Efectivo $X | Tarjeta $X | Transferencia $X
- Calculado en backend con los mismos filtros, no en frontend.

**Cada item de la lista:**

- Folio de la venta (ej: `S-00042`)
- Monto del pago
- Badge de método (verde=efectivo, azul=tarjeta, morado=transferencia)
- Nombre del cajero
- Hora del pago
- Badge "Editado" (naranja) si `updated_by IS NOT NULL`

**Ordenamiento:** Más reciente primero. Cursor-based pagination con infinite scroll.

### Panel derecho — Detalle del pago seleccionado

- Datos del pago: método, monto, fecha completa, cobrado por, editado por (si aplica, con nombre + fecha)
- Datos de la venta asociada: folio, total, estado actual, tabla de productos
- Lista de todos los pagos de esa venta (para contexto)

### Rutas

```
GET /{tenant}/sucursal/pagos    -> Sucursal\PagosController@index
GET /{tenant}/caja/pagos        -> Caja\PagosController@index
```

### Backend — PagosController

**Query base:**

```php
Payment::whereHas('sale', fn($q) => $q->where('branch_id', $user->branch_id))
```

**Filtros aplicados:**

- Fecha: default hoy (`whereDate('payments.created_at', today())`)
- Método: `where('method', $request->method)`
- Usuario (solo admin): `where('user_id', $request->user_id)`
- Cajero: forzar `where('user_id', auth()->id())`

**Eager load:**

```php
->with(['sale:id,folio,total,status,branch_id,amount_paid,amount_pending', 'user:id,name', 'updatedByUser:id,name'])
```

**Totales agregados:** Query separada con los mismos filtros, agrupando por método.

**Cursor pagination:** 20 por página.

### Middleware

- Sucursal: `role:admin-sucursal|superadmin`
- Caja: `role:cajero|superadmin`
- Ambos con `resolve.tenant` + `ensure.tenant`

### Sidebar

Agregar "Pagos" al sidebar de ambos layouts:

- `SucursalLayout.vue` — después de "Mesa de Trabajo"
- `CajeroLayout.vue` — agregar item "Pagos"

### Archivos a crear

- `app/Http/Controllers/Sucursal/PagosController.php`
- `app/Http/Controllers/Caja/PagosController.php`
- `resources/js/Pages/Sucursal/Pagos/Index.vue`
- `resources/js/Pages/Caja/Pagos/Index.vue`

### Archivos a modificar

- `resources/js/Layouts/SucursalLayout.vue` — agregar item sidebar
- `resources/js/Layouts/CajeroLayout.vue` — agregar item sidebar
- `routes/web.php` — agregar rutas

---

## Seguridad y multi-tenant

- Todas las queries filtran por `branch_id` del usuario autenticado.
- Cajero forzado a `where('user_id', auth()->id())` en backend.
- Middleware `resolve.tenant` + `ensure.tenant` en todas las rutas nuevas.
- Pagos soft-deleted (ventas canceladas) excluidos automáticamente por Eloquent `SoftDeletes`.
- Lock check en cambios de estado del cajero.

## Edge cases

- **Pago soft-deleted:** Eloquent excluye automáticamente. Sin lógica extra.
- **Cajero pausa venta locked por otro:** Se valida lock existente, retorna error 409.
- **Totales con pagination:** Calculados en backend con query separada (no dependen de la página actual).

## Sin cambios en

- Modelo `Payment` (ya tiene `user_id` y `updated_by`)
- Modelo `Sale` / `SaleStatus` enum
- `PaymentController` existente
- Migraciones (no se necesitan nuevas columnas)
- Permisos de edición/eliminación de pagos (sigue siendo solo admin)

## Resumen de archivos

### Crear

| Archivo                                             | Descripción             |
| --------------------------------------------------- | ----------------------- |
| `app/Http/Controllers/Sucursal/PagosController.php` | Controller pagos admin  |
| `app/Http/Controllers/Caja/PagosController.php`     | Controller pagos cajero |
| `resources/js/Pages/Sucursal/Pagos/Index.vue`       | Vista pagos admin       |
| `resources/js/Pages/Caja/Pagos/Index.vue`           | Vista pagos cajero      |

### Modificar

| Archivo                                             | Cambio                             |
| --------------------------------------------------- | ---------------------------------- |
| `resources/js/Pages/Sucursal/Workbench.vue`         | Chips de filtro                    |
| `resources/js/Pages/Caja/Workbench.vue`             | Chips de filtro + pausar/reactivar |
| `resources/js/Components/SaleContextMenu.vue`       | Refactor a allowed-actions         |
| `resources/js/Pages/Sucursal/Historial/Index.vue`   | Adaptar al nuevo prop de SaleContextMenu |
| `resources/js/Layouts/SucursalLayout.vue`           | Item "Pagos" en sidebar            |
| `resources/js/Layouts/CajeroLayout.vue`             | Item "Pagos" en sidebar            |
| `app/Http/Controllers/Caja/WorkbenchController.php` | Agregar updateStatus               |
| `routes/web.php`                                    | Rutas de pagos + estado cajero     |
