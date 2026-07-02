# Fusión del módulo de Compras en una sola página con tabs

**Fecha:** 2026-06-26
**Estado:** Diseño aprobado — pendiente de implementación
**Enfoque elegido:** A (barra de tabs compartida sobre las páginas existentes)

## Problema

El módulo de Compras ocupa hoy **tres entradas separadas** en el sidebar de los
roles Empresa y Sucursal:

- Proveedores
- Compras
- Productos de compra

Las tres pertenecen al mismo dominio (aprovisionamiento), por lo que tres ítems
distintos generan ruido visual en el sidebar. El objetivo es presentarlas como
**una sola sección "Compras" con tabs** para alternar entre ellas.

## Objetivo

- Colapsar las tres entradas del sidebar en **una sola: "Compras"**.
- Permitir alternar entre Compras / Proveedores / Productos mediante una barra de
  tabs en la parte superior del contenido.
- No alterar la lógica de backend, controladores ni carga de datos.

## Alcance

| Rol      | Cambio                                                              |
| -------- | ------------------------------------------------------------------ |
| Empresa  | Fusión de las 3 secciones en 1 ítem + barra de tabs                |
| Sucursal | Fusión de las 3 secciones en 1 ítem + barra de tabs                |
| Caja     | **Sin cambios** (solo tiene Compras; no ve Proveedores ni Productos)|

Fuera de alcance: rediseño de las páginas índice, del detalle de proveedor, o de
la carga de datos. Tampoco se fusionan los controladores.

## Enfoque (A) — Barra de tabs compartida sobre las rutas existentes

Las tres rutas, controladores y páginas índice **se conservan intactos**. La
"fusión" es puramente de navegación:

1. Un componente nuevo `<ComprasTabs>` se renderiza arriba del contenido de cada
   una de las tres páginas índice.
2. Cada tab es un `<Link>` de Inertia a la ruta existente de su sección
   (navegación SPA, rápida).
3. El sidebar pasa de 3 ítems a 1 ("Compras"), resaltado en cualquiera de los 3
   tabs o en el detalle de un proveedor.

### Por qué A y no una página única con `?tab=`

Los patrones existentes de tabs (Gastos, Productos) funcionan con `?tab=` porque
"Categorías" es un **sub-recurso del mismo controlador**. En Compras, las tres
secciones son **recursos peer con tres controladores y traits distintos**
(`HandlesPurchases`, `HandlesProviderWrites`, `HandlesPurchaseProduct*`, etc.) y
con permisos/deletes que difieren entre Empresa y Sucursal. Forzarlas a un solo
controlador acoplaría cosas hoy independientes y multiplicaría el riesgo. El
enfoque A logra el mismo objetivo de UX con cambio mínimo y respeta la
arquitectura de 3 recursos separados.

## Comportamiento de navegación

- **Sidebar:** un solo ítem "Compras" (ícono `compras`). Apunta a
  `*.compras.index` y queda activo en los 3 tabs y en el detalle de proveedor.
- **Tabs:** orden `Compras · Proveedores · Productos`. Landing = Compras.
- **Cambio de tab:** `<Link>` a la ruta índice existente de cada sección.
- **Detalle de proveedor (`Proveedores/Show`):** el clic en un proveedor sigue
  navegando a su página de detalle completa (con su back y sus sub-tabs internos).
  Ahí **no** se muestra la barra `<ComprasTabs>` (es un drill-down), pero el ítem
  "Compras" del sidebar permanece activo.
- **Deep links / enlaces existentes:** como las rutas no cambian, cualquier
  botón o enlace que ya apunte a estas secciones sigue funcionando.

## Componentes y archivos

### Nuevo

- `resources/js/Components/Compras/ComprasTabs.vue`
  - **Qué hace:** renderiza la barra de 3 tabs y resalta el activo.
  - **Cómo se usa:** recibe el prefijo de rol (`'empresa'` | `'sucursal'`) y el
    slug del tenant; construye los `<Link>` a `${prefijo}.compras.index`,
    `${prefijo}.proveedores.index`, `${prefijo}.productos-compra.index`; determina
    el tab activo con `route().current()`.
  - **De qué depende:** Ziggy (`route()`), Inertia `<Link>`. Estilo tomado del
    patrón de tabs ya usado en Productos/Gastos.
  - **Responsive:** scroll horizontal si los 3 tabs no caben en móvil.

### Editar

- `resources/js/Layouts/EmpresaLayout.vue`
  - Quitar los ítems `Proveedores` y `Productos de compra`; dejar solo `Compras`.
  - Generalizar `isActive` para aceptar `matches: string[]` y marcar "Compras"
    activo en `empresa.compras*`, `empresa.proveedores*`,
    `empresa.productos-compra*`.
- `resources/js/Layouts/SucursalLayout.vue`
  - Igual que arriba, con prefijos `sucursal.*`. El `isActive` ya soporta
    `match` + `extraMatch`; se generaliza a `matches: string[]` sin romper los
    ítems que usan `match`/`extraMatch`.
- Páginas índice (insertar `<ComprasTabs>` arriba del contenido, sin tocar su
  lógica):
  - `resources/js/Pages/Empresa/Compras/Index.vue`
  - `resources/js/Pages/Empresa/Proveedores/Index.vue`
  - `resources/js/Pages/Empresa/ProductosCompra/Index.vue`
  - `resources/js/Pages/Sucursal/Compras/Index.vue`
  - `resources/js/Pages/Sucursal/Proveedores/Index.vue`
  - `resources/js/Pages/Sucursal/ProductosCompra/Index.vue`

## Resaltado activo (detalle técnico)

Hoy:

- `EmpresaLayout.isActive` solo soporta un `match`.
- `SucursalLayout.isActive` soporta `match` + un `extraMatch`.

Se necesita que "Compras" matchee **tres** prefijos de ruta. Solución:
generalizar ambos `isActive` para aceptar una propiedad opcional
`matches: string[]` en el link; si está presente, el ítem está activo cuando
`route().current(prefix + '*')` es verdadero para **cualquiera** de los prefijos.
Los `match`/`extraMatch` existentes de otros ítems siguen funcionando
(compatibilidad hacia atrás).

Entrada resultante del sidebar (ejemplo Empresa):

```js
{
  label: 'Compras',
  route: 'empresa.compras.index',
  matches: ['empresa.compras', 'empresa.proveedores', 'empresa.productos-compra'],
  icon: 'compras',
}
```

## Edge cases

- **Caja:** sin cambios; no se toca `CajeroLayout.vue`.
- **Permisos en Sucursal:** las páginas mantienen sus gates de feature
  (p. ej. escritura de proveedores con `branch.feature`). La barra de tabs solo
  navega; no añade ni quita permisos.
- **Móvil:** la barra de tabs hace scroll horizontal si no caben los 3.

## Verificación

- Las páginas índice ya tienen su comportamiento; no se modifica su lógica de
  datos, por lo que no se requieren nuevas pruebas de backend.
- Verificación manual: en Empresa y Sucursal, confirmar que (1) el sidebar
  muestra un solo ítem "Compras", (2) los 3 tabs navegan correctamente, (3) el
  ítem queda resaltado en los 3 tabs y en el detalle de proveedor, (4) Caja sigue
  igual.

## Riesgos

- Bajo. El cambio es de navegación/UI; las rutas y la carga de datos no cambian.
  El mayor punto de cuidado es la generalización de `isActive` en los dos layouts
  (no romper los ítems existentes que usan `match`/`extraMatch`).
