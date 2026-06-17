# Productos de compra: acceso del admin-sucursal, historial e interfaz renovada

**Fecha:** 2026-06-17
**Estado:** Aprobado — pendiente de plan de implementación

## Problema

El catálogo de **Productos de compra** (`PurchaseProduct`, *tenant-wide*) hoy solo lo
gestiona el `admin-empresa`. El `admin-sucursal` no tiene pantalla para verlo ni
editarlo. Además:

- No existe **historial de cambios**: no se sabe quién creó o editó un producto ni cuándo.
- La interfaz actual (`Empresa/ProductosCompra/Index.vue`) es una tabla básica: sin
  resumen, sin filtro por categoría (el backend ya lo soporta pero la UI no lo expone),
  con la categoría siempre en "—", `confirm()` nativo para borrar y acciones de texto.

Se quiere: (1) que el admin-sucursal pueda **ver y editar** el catálogo cuando la empresa
lo habilita para su sucursal, (2) **historial de cambios** (quién y cuándo), y (3) una
**interfaz más moderna e intuitiva**.

## Decisiones de diseño (acordadas con el usuario)

1. **Catálogo compartido (sin cambio de modelo de datos).** `PurchaseProduct` sigue
   siendo tenant-wide. No se agrega `branch_id`. El toggle solo concede permiso al
   admin-sucursal sobre el catálogo común. Mismo enfoque que
   `branch_admin_providers_enabled` (ver `2026-05-29-admin-sucursal-catalogos-design.md`).

2. **Nuevo toggle por sucursal.** Columna boolean `branch_admin_purchase_products_enabled`
   en `branches`, default `false` (opt-in), patrón idéntico a `branch_admin_providers_enabled`.

3. **Toggle OFF → módulo oculto.** A diferencia de Proveedores (lectura siempre abierta),
   aquí cuando el toggle está apagado el admin-sucursal **no ve el módulo**: el ítem de
   menú no aparece y **todas** las rutas de sucursal (incl. `index`) dan 403. Cuando está
   encendido puede listar, crear, editar y activar/desactivar.

4. **Alcance del admin-sucursal: crear/editar/activar-desactivar, NO eliminar.** El
   `destroy` queda reservado a `admin-empresa`/`superadmin`. `superadmin` pasa siempre.

5. **Historial: eventos `Created` y `Updated`.** El diff de `Updated` captura los cambios
   de Nombre, Unidad, Categoría y Estado (activo/inactivo) — la desactivación queda
   registrada como un cambio del campo `status`. **No** se agrega un evento `Deleted`
   dedicado (un producto borrado es soft-delete y desaparece de la lista; su historial
   no es alcanzable). Se reutiliza la infraestructura existente de auditoría.

## Backend

### Migración + modelo
- Migración `add_branch_admin_purchase_products_enabled_to_branches`: un `boolean`
  default `false`, `->after('branch_admin_expense_categories_enabled')`.
- `Branch`: añadir `branch_admin_purchase_products_enabled` a `#[Fillable(...)]` y a
  `casts()` (`'boolean'`).
- `PurchaseProduct`: añadir el trait `App\Models\Concerns\RecordsHistory` (ya existente).
  El modelo ya usa `BelongsToTenant` y `SoftDeletes`.

### Auditoría / historial
- Reutilizar `App\Services\AuditLogger`, modelo `App\Models\AuditLog`, enum `AuditEvent`
  y el componente `HistorialTimeline.vue` (mismos que Compras/Gastos).
- Añadir a `AuditLogger` un método `purchaseProductSnapshot(PurchaseProduct $p): array`
  con shape `{fields, items}`, donde `fields` guarda **valores ya legibles**:
  `name`, `unit`, `category` (label del enum o `null`), `status` (`'Activo'`/`'Inactivo'`),
  e `items => []`.
- En `store`: `AuditLogger::logCreated($product)`.
- En `update`: tomar snapshot antes y después y llamar `logUpdatedIfChanged($product, $before, $after)`.

### Concern compartido
- Nuevo `App\Http\Controllers\Concerns\HandlesPurchaseProductWrites` (espejo de
  `HandlesProviderWrites`): `store(Request)`, `update(Request, PurchaseProduct)` y
  `validatedPurchaseProductRequest(...)`. Incluye el logging de auditoría. **No** incluye
  `destroy`. La validación es la actual de `Empresa\PurchaseProductController::validated()`
  (unicidad de `name` por tenant ignorando soft-deletes; `unit` requerido; `category`
  enum nullable; `status` `in:active,inactive` solo en update).

### Controladores
- `Empresa\PurchaseProductController`:
  - Mueve `store`/`update` al concern (deja de duplicar la lógica).
  - Conserva `destroy` (regla actual: no borrar si tiene `purchase_items`; soft-delete si no).
  - `index` pasa además: `stats` (total / activos / inactivos / sin categoría, sobre todo
    el catálogo del tenant, independiente del filtro) y, por producto, `last_edited`
    (`{by, at}`). `last_edited` se resuelve con **una sola query agregada** a `audit_logs`
    (último registro por `auditable_id` del tipo `PurchaseProduct` para el tenant, con
    join a `users` para el nombre), evitando N+1.
  - Nuevo `history(PurchaseProduct $producto_compra)`: devuelve JSON con las entradas de
    `$producto_compra->history()->with('user')->get()` serializadas
    (`event`, `user_name`, `created_at`, `changes`). Valida pertenencia al tenant.
- Nuevo `Sucursal\PurchaseProductController`:
  - Usa `HandlesPurchaseProductWrites` (gana `store`/`update`).
  - `index` (misma serialización + `stats` + `last_edited`) y `history` (idéntico al de
    Empresa). Sin `destroy`.
  - Pasa `canManage = true` a la vista (el acceso ya está gateado por middleware; el flag
    sirve para que el componente compartido decida `canDelete=false`).

### Rutas
- Empresa (`/{tenant}/empresa`, ya existentes + nueva de historial):
  `productos-compra.index|store|update|destroy` + `productos-compra.historial`
  (`GET productos-compra/{producto_compra}/historial`).
- Sucursal (`/{tenant}/sucursal`), **todo el grupo** bajo
  `branch.feature:branch_admin_purchase_products_enabled`:
  `sucursal.productos-compra.index`, `…historial`, `…store`, `…update`. Sin `destroy`.

### Exposición del flag al frontend
- `HandleInertiaRequests`: añadir `branch_admin_purchase_products_enabled => (bool) $branch->...`
  al objeto `auth.branch` que ya se comparte, para que `SucursalLayout` decida mostrar el
  ítem de menú.

## Frontend

### Configuración del toggle
- `Empresa/Sucursales/Edit.vue`: tercer switch en la sección *"Gestión de catálogos por el
  admin de sucursal"* ("Gestionar productos de compra"). Añadir el campo al `useForm` y a
  la validación de `Empresa\SucursalController::update` (`'sometimes|boolean'`).

### Menú
- `SucursalLayout.vue`: nuevo ítem "Productos de compra" (ruta `sucursal.productos-compra.index`),
  visible solo si `auth.branch.branch_admin_purchase_products_enabled`. Icono SVG reutilizando
  el estilo heroicons del layout.

### Componente compartido
- Nuevo `Components/Compras/PurchaseProductsManager.vue` (presentacional): toolbar (búsqueda
  con debounce, segmented control de estado, filtro por categoría), tarjetas de resumen
  (stats), tabla (badges de categoría a color, pill de estado, columna "Última edición" que
  abre el historial, acción ✏️ editar + menú `⋯` con "Ver historial" / "Eliminar"),
  modal de formulario y drawer de historial. Props: `products`, `filters`, `categories`,
  `stats`, `routePrefix` (`'empresa'|'sucursal'`), `tenantSlug`, `canDelete`.
- `Empresa/ProductosCompra/Index.vue` y nuevo `Sucursal/ProductosCompra/Index.vue`:
  cada uno envuelve el manager en su layout. Empresa pasa `canDelete=true`; sucursal `false`.
- `ProductoCompraFormModal.vue`: parametrizar con `routePrefix` (default `'empresa'`) para
  construir `…productos-compra.store|update`.
- Nuevo `Components/Compras/ProductoCompraHistorialDrawer.vue`: al abrir hace `fetch`/`router`
  al endpoint `…productos-compra.historial`, muestra estado de carga y renderiza
  `HistorialTimeline`.
- `HistorialTimeline.vue`: añadir a `FIELD_LABEL` las claves `name → 'Nombre'`,
  `unit → 'Unidad'`, `category → 'Categoría'`, `status → 'Estado'` (cambio aditivo; los
  valores ya llegan legibles desde el snapshot, no requiere mapear en el front).
- Reemplazar el `confirm()` nativo de borrado por un diálogo de confirmación (el patrón de
  modal ya usado en la app).

## Seguridad

El toggle se verifica **siempre en servidor**: el middleware `branch.feature` protege todo
el grupo de rutas de sucursal (incluido `index`), no solo se oculta la UI. `TenantScope`
sigue garantizando el aislamiento cross-tenant; cada `update`/`destroy`/`history` valida
`tenant_id` explícitamente.

## Pruebas (PHPUnit, feature)

- **Empresa:** crea/edita/elimina; al crear y editar se registra `AuditLog` con `user_id`
  y diff correcto; el endpoint de historial devuelve las entradas.
- **Sucursal toggle ON:** ve `index`, crea y edita; el historial registra al usuario de
  sucursal; **no** existe ruta/permiso de `destroy` (404/403).
- **Sucursal toggle OFF:** `index`, `store`, `update`, `historial` → 403.
- **`superadmin`:** bypass del middleware en rutas de sucursal.
- **Aislamiento cross-tenant:** `update`/`history` de un producto de otro tenant → 403/404.
- **`stats` y `last_edited`:** valores correctos y sin N+1 (una query de auditoría agregada).

## Documentación

Actualizar `docs/modulos/compras.md` (matriz de permisos + nuevo toggle
`branch_admin_purchase_products_enabled` + historial de productos de compra).
