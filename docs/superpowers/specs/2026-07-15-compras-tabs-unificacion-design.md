# Unificar Compras, Productos de compra y Proveedores en una entrada con tabs

**Fecha:** 2026-07-15
**Estado:** Aprobado — pendiente de plan
**Alcance:** solo navegación/frontend en `carniceria-saas` (roles admin-empresa y admin-sucursal). Cero cambios de backend. El hub Electron adopta esto después, como trabajo de paridad separado.

## Problema

El sidebar acumula demasiadas entradas (15 en Sucursal, 13 en Empresa) y tres pertenecen al mismo dominio: **Proveedores**, **Compras** y **Productos de compra**. El usuario quiere simplificar la navegación agrupándolas en una sola entrada con tabs superiores.

## Decisión

**Una sola entrada "Compras" en el sidebar con 3 tabs superiores** (Compras | Productos de compra | Proveedores), implementada como **tabs de navegación sobre las rutas existentes** — las páginas, controladores, rutas, permisos y feature flags quedan intactos.

Decisiones tomadas con el usuario (2026-07-15, con mockups en visual companion):

1. **Agrupación total** (opción A): las 3 pantallas bajo una entrada; el sidebar de Sucursal pasa de 15 → 13 entradas y el de Empresa de 13 → 11.
2. **Segundo nivel subordinado** (opción A): dentro del tab "Productos de compra", el manejo Productos/Categorías se queda tal cual existe hoy (segmented control), sin promover Categorías a tab de primer nivel ni moverla a un modal.
3. **Tabs de navegación, rutas intactas**: cada tab es un `<Link>` de Inertia a la ruta actual de cada sección. Se descartó la página contenedora única (refactor grande, rompe URLs y tests, mismo resultado visual).
4. **Hub después**: la paridad del hub se agenda aparte, conforme a la regla de trackear la paridad visual separada del resto.

### Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Grupo colapsable en el sidebar (sin tabs) | Sigue habiendo 3 destinos y ruido vertical; el usuario prefirió tabs. |
| Proveedores como entrada aparte (solo 2 tabs) | El usuario quiso agrupación total. |
| Página contenedora única con partial reloads | Refactor de controladores, rompe URLs/bookmarks y tests; Inertia ya da navegación SPA entre rutas. |
| 4 tabs planos (Categorías promovida) | Mezcla niveles de jerarquía y satura la barra. |

## Diseño

### 1. Sidebar (`EmpresaLayout.vue`, `SucursalLayout.vue`)

- Se eliminan las entradas **Proveedores** y **Productos de compra**.
- Queda **Compras** → `{prefix}.compras.index`, activa cuando la ruta actual pertenece a cualquiera de los tres prefijos (`{prefix}.compras`, `{prefix}.productos-compra`, `{prefix}.proveedores`). El mecanismo `match`/`extraMatch` de los nav items se extiende para aceptar múltiples patrones (p. ej. `match` como arreglo), manteniendo compatibilidad con los items existentes.

### 2. Componente `Components/Compras/ComprasTabs.vue`

- Barra de 3 tabs con el estilo Tailwind de la web (borde inferior, tab activo resaltado), consistente con el patrón visual existente.
- Cada tab es `<Link>` a la ruta existente de su sección.
- Prop `active`: `'compras' | 'productos-compra' | 'proveedores'`.
- Infiere el prefijo de rol (`empresa`/`sucursal`) y el slug del tenant desde la ruta actual (`route().current()` / props de página). Sin estado propio ni lógica de datos.

### 3. Páginas que montan el componente (7)

| Página | Tab activo |
|---|---|
| `Empresa/Compras/Index.vue`, `Sucursal/Compras/Index.vue` | Compras |
| `Empresa/ProductosCompra/Index.vue`, `Sucursal/ProductosCompra/Index.vue` | Productos de compra |
| `Empresa/Proveedores/Index.vue`, `Sucursal/Proveedores/Index.vue` | Proveedores |
| `Proveedores/Show` (detalle de proveedor, ambos roles) | Proveedores |

- El detalle de proveedor conserva la barra (con "Proveedores" activo) para no perder contexto y permitir el salto directo entre secciones.
- Headers, KPIs, botones ("Nueva compra", "✨ Capturar con IA"), modales y el segmented Productos/Categorías de cada página **no se tocan**.

### 4. Backend, permisos y pruebas

- **Cero cambios** en rutas, controladores, middleware, permisos o feature flags. URLs estables (bookmarks y deep links siguen funcionando).
- Las lecturas de las tres secciones no dependen de feature flags en Sucursal (los flags `branch_admin_providers_enabled` etc. solo controlan escrituras), así que los 3 tabs se muestran siempre.
- Cajero no se toca (su pantalla de compras vive en `/caja` con su propio layout).
- Tests backend existentes del módulo siguen intactos. Verificación: `npm run build` + recorrido manual de los 3 tabs en ambos roles.

## Documentación

- `docs/modulos/compras.md` — actualizar la sección de frontend/navegación al implementar.
- Este spec: cambiar `Estado:` a "Implementado" al terminar, enlazando al doc vivo.

## Fuera de alcance

- Paridad del hub Electron (trabajo posterior separado).
- Cualquier agrupación adicional del sidebar (p. ej. Gastos/Cortes) — si se quiere, será otro spec.
- Cambios de contenido dentro de las pantallas (KPIs, tablas, modales).
