# Gestión de catálogos por admin-sucursal (habilitable por empresa)

**Fecha:** 2026-05-29
**Estado:** Aprobado — en implementación

## Problema

Hoy los **proveedores** y las **categorías/subcategorías de gastos** son catálogos
*tenant-wide*: solo el `admin-empresa` (y `superadmin`) los crea/edita/borra. El
`admin-sucursal` solo los consulta. Se quiere que la empresa pueda **delegar** la
gestión de estos catálogos al admin-sucursal, reutilizando la UI, los controladores
y las funciones de IA ya existentes.

## Decisiones de diseño (acordadas con el usuario)

1. **Catálogo compartido (sin cambio de modelo de datos).** Los proveedores y las
   categorías siguen siendo tenant-wide. No se agrega `branch_id` a esas tablas. El
   toggle solo concede *permiso de escritura* al admin-sucursal sobre el catálogo
   común; lo que crea una sucursal lo ven la empresa y las demás sucursales.

2. **Toggles por sucursal.** Dos columnas booleanas nuevas en `branches`
   (patrón idéntico a `cashier_expenses_enabled`). Cada admin-sucursal hereda el
   toggle de `branches` correspondiente a su `branch_id`.

3. **Toggles separados.**
   - `branch_admin_providers_enabled` → crear/editar proveedores.
   - `branch_admin_expense_categories_enabled` → crear/editar categorías,
     subcategorías y la IA de categorías.

4. **Alcance: crear y editar, NO borrar.** El admin-sucursal puede crear y editar
   cualquier registro del catálogo compartido, pero **no eliminar/destruir**. El
   borrado queda reservado a `admin-empresa`/`superadmin`.

5. **Default `false` (opt-in).** La empresa debe conceder explícitamente.

## Backend

### Migración + modelo
- Migración `add_branch_admin_catalog_toggles_to_branches`: dos `boolean` default
  `false`. Añadir a `Branch` `#[Fillable(...)]` y `casts()`.

### Middleware de gating
- `App\Http\Middleware\EnsureBranchFeature` (alias `branch.feature`). Recibe el
  nombre de columna como parámetro, resuelve el `branch` del usuario autenticado
  (`$user->branch_id`) y `abort(403)` si la bandera está apagada. `superadmin`
  pasa siempre. Se registra el alias en `bootstrap/app.php`.

### Reutilización vía Concerns
La lógica de escritura se extrae de los controladores `Empresa\*` a concerns
compartidos, que tanto Empresa como Sucursal usan (`destroy` se queda solo en
Empresa):
- `Concerns\HandlesProviderWrites` — `store`, `update`, `validatedFromRequest`.
- `Concerns\HandlesExpenseCategoryWrites` — `store`, `update`, `storeFromAiDraft` + helpers.
- `Concerns\HandlesExpenseSubcategoryWrites` — `store`, `update` + helpers.

### Controladores Sucursal
- `Sucursal\ProviderController` gana `store`/`update` (usa el concern); `index`
  expone `canManage` = toggle de su branch.
- Nuevos `Sucursal\ExpenseCategoryController` y `Sucursal\ExpenseSubcategoryController`
  (usan los concerns); `Sucursal\GastoController@index` expone `canManageCategories`
  y `tab`.

### Rutas (prefijo `/{tenant}/sucursal`)
Agrupadas bajo el middleware `branch.feature`:
- `branch.feature:branch_admin_providers_enabled`: `proveedores.store`, `proveedores.update`.
- `branch.feature:branch_admin_expense_categories_enabled`: `gastos.categorias.store|update`,
  `gastos.subcategorias.store|update`, `gastos.categorias.ia.store`, `gastos.categorias.ia.apply`.

La IA reutiliza el mismo `Ai\CategoryDraftController@store` (es role-agnóstico).

## Frontend

- `Empresa/Sucursales/Edit.vue`: dos checkboxes nuevos junto a los toggles de cajero.
- Extraer `Components/Gastos/CategoriasManager.vue` (tab de categorías completa, con
  CRUD + IA), parametrizado por `routePrefix`, `tenantSlug`, `categories`, `canDelete`.
  Usado por Empresa (canDelete=true) y Sucursal (canDelete=false).
- `useCategoryAiDraft` + `CategoryAICaptureModal`/`CategoryAIReviewModal`: aceptan
  `routePrefix` (default `'empresa'`) para construir las rutas IA.
- `ProveedorFormModal`: acepta `routePrefix` (default `'empresa'`).
- `Sucursal/Gastos/Index.vue`: pestañas Gastos/Categorías (la pestaña Categorías solo
  cuando `canManageCategories`).
- `Sucursal/Proveedores/Index.vue`: botón "+ Nuevo" y acción Editar cuando `canManage`,
  reutilizando `ProveedorFormModal`. Sin borrar.

## Seguridad
El toggle se verifica **siempre en servidor** (middleware en cada ruta de escritura),
no solo ocultando UI. `TenantScope` sigue garantizando aislamiento cross-tenant.

## Pruebas
- Toggle off → 403 en cada `store`/`update` (proveedores y categorías).
- Toggle on → éxito; registro visible para empresa.
- `destroy` desde sucursal → 403 / ruta inexistente.
- IA de categorías gated por su toggle.
- Aislamiento cross-tenant.

## Documentación
Actualizar `docs/modulos/gastos.md` y `docs/modulos/compras.md` (matrices de permisos
+ nuevos toggles).
