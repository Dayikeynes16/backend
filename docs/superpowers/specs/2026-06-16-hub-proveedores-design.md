# Carnicería Hub — Módulo Proveedores (Fase 4: admin-sucursal) — Diseño

**Fecha:** 2026-06-16
**Estado:** En implementación
**Patrón:** sigue la receta de `2026-06-01-hub-migracion-modulos-fase1-design.md`

## En palabras simples

Migrar el módulo **Proveedores** de la web (Inertia `Sucursal/Proveedores`) al hub de
escritorio (Vue + Material Design 3). Es el primer módulo **admin-sucursal** del hub
(Fase 4). Reusa el catálogo tenant-wide y la validación ya compartida en el Concern
`HandlesProviderWrites` (mergeado con la delegación admin-sucursal de catálogos).

## Decisiones

1. **Módulo admin-sucursal.** `hub.role` ya restringe a `cajero`/`admin-sucursal`; este
   módulo además **exige rol `admin-sucursal`** (como `Config`). El cajero ve los
   proveedores únicamente dentro de Compras (endpoint de compras), no aquí.
2. **Catálogo tenant-wide compartido** (sin `branch_id`). **Lectura siempre** para el
   admin-sucursal; **crear/editar** solo si la empresa habilitó el toggle
   `branch_admin_providers_enabled` de su sucursal (mismo toggle que la web). **Sin
   borrar** (el `destroy` queda en empresa).
3. **Reusa la validación compartida** `HandlesProviderWrites::validatedProviderRequest`
   (no diverge reglas). El controlador del hub sobreescribe `store`/`update` para
   devolver JSON (el trait devuelve redirects Inertia).
4. **Sin route-model-binding** de `Provider` (patrón hub): los métodos reciben el id y
   resuelven con scope de tenant explícito; cross-tenant → 404.

## Backend (`/api/v1/hub/*`, `auth:sanctum` + `hub.role`)

| Endpoint | Acceso | Comportamiento |
| --- | --- | --- |
| `GET hub/providers?q=&type=` | admin-sucursal/superadmin | Lista (búsqueda por nombre, filtro por tipo). Cuando `can_manage` muestra también inactivos. Devuelve `data`, `can_manage`, `types`. |
| `POST hub/providers` | admin-sucursal + toggle | Crea proveedor (status `active`). 403 si toggle off. |
| `PUT hub/providers/{provider}` | admin-sucursal + toggle | Edita (incluye `status`). 403 si toggle off; 404 cross-tenant. |

- `Api/Hub/ProviderController` (`use HandlesProviderWrites`), `HubProviderResource`,
  `ProviderType::label()` (nuevo, reutilizado por controller y resource).
- `branch_id`/`user` del token; `app()->instance('tenant', $user->tenant)` fija el scope.

## Hub (Electron)

- **main**: `src/main/api/providers.js` (`list/create/update`), `HttpClient.put()`,
  IPC `api:providers:*`, expuesto como `window.hub.api.providers.*` (token solo en main).
- **renderer**: `ProvidersView.vue` (MD3): búsqueda + filtro de tipo, lista, y formulario
  inline de alta/edición visible solo si `can_manage` (sin borrar). Ruta
  `meta.role: 'admin-sucursal'`; enlace en `ShellLayout` gateado por rol.

## Pruebas

- **PHPUnit** (`tests/Feature/Api/Hub/ProviderApiTest.php`): index (admin-sucursal),
  403 cajero, 403 admin-empresa (hub.role), store/update con toggle on, 403 con toggle
  off, sin destroy (405), aislamiento cross-tenant (404).
- **Vitest** (`test/api-providers.test.js`): `list/create/update` (URLs, query
  codificada, mapeo de body, `status` solo en update).

## Fuera de alcance

Borrado (queda en empresa), detalle de proveedor (deuda/compras/pagos — futuro),
gestión offline.
