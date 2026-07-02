# Fusión de Compras en tabs — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Colapsar las tres entradas del sidebar (Compras, Proveedores, Productos de compra) en una sola "Compras" con una barra de tabs compartida, en los roles Empresa y Sucursal.

**Architecture:** Enfoque A — navegación. Las rutas, controladores y carga de datos no cambian. Se agrega un componente `ComprasTabs.vue` que navega entre las rutas índice existentes vía `<Link>` de Inertia, se inserta arriba del contenido de las 6 páginas índice, y el sidebar pasa de 3 ítems a 1 (con `isActive` generalizado a un array `matches[]`).

**Tech Stack:** Vue 3 (`<script setup>`), Inertia.js v2 (`<Link>`, `usePage`), Ziggy (`route()`), Tailwind v3. Sin runner de tests JS: verificación por `vite build` + chequeo manual.

**Spec:** `docs/superpowers/specs/2026-06-26-compras-modulo-tabs-design.md`

---

## Estructura de archivos

| Archivo | Acción | Responsabilidad |
| --- | --- | --- |
| `resources/js/Components/Compras/ComprasTabs.vue` | Crear | Barra de 3 tabs; navega a las rutas índice y resalta el tab activo |
| `resources/js/Layouts/EmpresaLayout.vue` | Modificar | 3 ítems → 1 "Compras"; `isActive` soporta `matches[]` |
| `resources/js/Layouts/SucursalLayout.vue` | Modificar | 3 ítems → 1 "Compras"; `isActive` soporta `matches[]` |
| `resources/js/Pages/Empresa/Compras/Index.vue` | Modificar | Insertar `<ComprasTabs prefix="empresa" />` |
| `resources/js/Pages/Empresa/Proveedores/Index.vue` | Modificar | Insertar `<ComprasTabs prefix="empresa" />` |
| `resources/js/Pages/Empresa/ProductosCompra/Index.vue` | Modificar | Insertar `<ComprasTabs prefix="empresa" />` |
| `resources/js/Pages/Sucursal/Compras/Index.vue` | Modificar | Insertar `<ComprasTabs prefix="sucursal" />` |
| `resources/js/Pages/Sucursal/Proveedores/Index.vue` | Modificar | Insertar `<ComprasTabs prefix="sucursal" />` |
| `resources/js/Pages/Sucursal/ProductosCompra/Index.vue` | Modificar | Insertar `<ComprasTabs prefix="sucursal" />` |

**Caja:** sin cambios (`CajeroLayout.vue` no se toca).

## Nota sobre verificación

No hay runner de tests JS en este proyecto (`package.json` solo tiene `build` y `dev`). Por eso:

- **Loop de desarrollo:** mantén el dev server corriendo en una terminal aparte —
  `vendor/bin/sail npm run dev` (o `npm run dev` si no usas Docker)— y vigila que no
  aparezcan errores de compilación en la terminal/navegador tras cada edición.
- **Verificación final:** `vendor/bin/sail npm run build` debe terminar sin errores, más el
  checklist manual de la Task 6.

## Nota sobre git

El repo está en la rama `main`. Trabaja en una rama nueva. Antes de la Task 1:

```bash
cd "/Users/sebas/Documents/version 2/carniceria-saas"
git checkout -b feat/compras-tabs
```

---

### Task 1: Crear el componente `ComprasTabs`

**Files:**
- Create: `resources/js/Components/Compras/ComprasTabs.vue`

- [ ] **Step 1: Crear el componente**

Crear `resources/js/Components/Compras/ComprasTabs.vue` con este contenido exacto:

```vue
<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    // 'empresa' | 'sucursal' — prefijo de los nombres de ruta Ziggy.
    prefix: { type: String, required: true },
});

const slug = computed(() => usePage().props.auth.tenant_slug);

const tabs = computed(() => [
    { label: 'Compras', route: `${props.prefix}.compras.index`, match: `${props.prefix}.compras` },
    { label: 'Proveedores', route: `${props.prefix}.proveedores.index`, match: `${props.prefix}.proveedores` },
    { label: 'Productos', route: `${props.prefix}.productos-compra.index`, match: `${props.prefix}.productos-compra` },
]);

const isActive = (tab) => route().current(tab.match + '*');
</script>

<template>
    <div class="overflow-x-auto border-b border-gray-200">
        <nav class="-mb-px flex gap-6" aria-label="Secciones de compras">
            <Link v-for="tab in tabs" :key="tab.route" :href="route(tab.route, slug)"
                :class="['shrink-0 whitespace-nowrap border-b-2 px-1 pb-3 pt-1 text-sm font-semibold transition',
                    isActive(tab)
                        ? 'border-red-600 text-red-600'
                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700']">
                {{ tab.label }}
            </Link>
        </nav>
    </div>
</template>
```

Notas: `route()` es global (Ziggy), no se importa — igual que en los layouts. El slug se lee
de `page.props.auth.tenant_slug`, así las páginas no tienen que pasarlo. El estilo replica la
barra de tabs de `Sucursal/Productos/Index.vue` (activo `border-red-600 text-red-600`).

- [ ] **Step 2: Verificar que compila**

Con el dev server corriendo, confirma que no hay error de compilación. El componente aún no se
usa en ninguna página, así que no se renderiza todavía; basta con que Vite no reporte error.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Compras/ComprasTabs.vue
git commit -m "feat(compras): add ComprasTabs navigation component"
```

---

### Task 2: Colapsar el sidebar de Empresa a un solo ítem "Compras"

**Files:**
- Modify: `resources/js/Layouts/EmpresaLayout.vue` (navLinks ~17-19, isActive ~28-31)

- [ ] **Step 1: Reemplazar los 3 ítems por 1 en `navLinks`**

Buscar estas tres líneas:

```js
    { label: 'Proveedores', route: 'empresa.proveedores.index', match: 'empresa.proveedores', icon: 'proveedores' },
    { label: 'Compras', route: 'empresa.compras.index', match: 'empresa.compras', icon: 'compras' },
    { label: 'Productos de compra', route: 'empresa.productos-compra.index', match: 'empresa.productos-compra', icon: 'proveedores' },
```

Reemplazarlas por:

```js
    { label: 'Compras', route: 'empresa.compras.index', matches: ['empresa.compras', 'empresa.proveedores', 'empresa.productos-compra'], icon: 'compras' },
```

- [ ] **Step 2: Generalizar `isActive` para soportar `matches[]`**

Buscar:

```js
const isActive = (link) => {
    if (link.match) return route().current(link.match + '*') || route().current(link.route);
    return route().current(link.route);
};
```

Reemplazar por:

```js
const isActive = (link) => {
    if (link.matches) return link.matches.some((m) => route().current(m + '*'));
    if (link.match) return route().current(link.match + '*') || route().current(link.route);
    return route().current(link.route);
};
```

- [ ] **Step 3: Verificar**

Con el dev server corriendo, recarga como admin-empresa (`admin@eltoro.test`). El sidebar debe
mostrar **un solo** ítem "Compras" (ya no "Proveedores" ni "Productos de compra"). Sin errores de
compilación.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/EmpresaLayout.vue
git commit -m "feat(compras): collapse empresa purchases sidebar into single item"
```

---

### Task 3: Colapsar el sidebar de Sucursal a un solo ítem "Compras"

**Files:**
- Modify: `resources/js/Layouts/SucursalLayout.vue` (navLinks ~27-29, isActive ~41-47)

- [ ] **Step 1: Reemplazar los 3 ítems por 1 en `baseNavLinks`**

Buscar estas tres líneas:

```js
    { label: 'Proveedores', route: 'sucursal.proveedores.index', match: 'sucursal.proveedores', icon: 'proveedores' },
    { label: 'Compras', route: 'sucursal.compras.index', match: 'sucursal.compras', icon: 'compras' },
    { label: 'Productos de compra', route: 'sucursal.productos-compra.index', match: 'sucursal.productos-compra', icon: 'productoscompra' },
```

Reemplazarlas por:

```js
    { label: 'Compras', route: 'sucursal.compras.index', matches: ['sucursal.compras', 'sucursal.proveedores', 'sucursal.productos-compra'], icon: 'compras' },
```

- [ ] **Step 2: Generalizar `isActive` para soportar `matches[]`**

Buscar:

```js
const isActive = (link) => {
    if (link.match) {
        if (route().current(link.match + '*') || route().current(link.route)) return true;
        if (link.extraMatch && route().current(link.extraMatch + '*')) return true;
    }
    return route().current(link.route);
};
```

Reemplazar por:

```js
const isActive = (link) => {
    if (link.matches) return link.matches.some((m) => route().current(m + '*'));
    if (link.match) {
        if (route().current(link.match + '*') || route().current(link.route)) return true;
        if (link.extraMatch && route().current(link.extraMatch + '*')) return true;
    }
    return route().current(link.route);
};
```

(El ítem "Productos" que usa `match` + `extraMatch` sigue funcionando: la rama nueva solo aplica
cuando el link define `matches`.)

- [ ] **Step 3: Verificar**

Con el dev server corriendo, recarga como admin-sucursal (`sucursal@eltoro.test`). El sidebar debe
mostrar **un solo** ítem "Compras". El ítem "Productos" (catálogo de venta) debe seguir resaltándose
normal. Sin errores de compilación.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/SucursalLayout.vue
git commit -m "feat(compras): collapse sucursal purchases sidebar into single item"
```

---

### Task 4: Insertar la barra de tabs en las 3 páginas de Empresa

**Files:**
- Modify: `resources/js/Pages/Empresa/Compras/Index.vue`
- Modify: `resources/js/Pages/Empresa/Proveedores/Index.vue`
- Modify: `resources/js/Pages/Empresa/ProductosCompra/Index.vue`

- [ ] **Step 1: `Empresa/Compras/Index.vue` — importar el componente**

En el bloque `<script setup>`, debajo de `import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';`, añadir:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 2: `Empresa/Compras/Index.vue` — insertar la barra**

Buscar (cierre del slot header seguido del contenedor de contenido):

```html
        </template>

        <div class="space-y-5">
```

Reemplazar por:

```html
        </template>

        <ComprasTabs prefix="empresa" class="mb-5" />

        <div class="space-y-5">
```

- [ ] **Step 3: `Empresa/Proveedores/Index.vue` — importar el componente**

Debajo de `import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';`, añadir:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 4: `Empresa/Proveedores/Index.vue` — insertar la barra**

Buscar:

```html
        </template>

        <div class="space-y-5">
```

Reemplazar por:

```html
        </template>

        <ComprasTabs prefix="empresa" class="mb-5" />

        <div class="space-y-5">
```

- [ ] **Step 5: `Empresa/ProductosCompra/Index.vue` — importar el componente**

Debajo de `import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';`, añadir:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 6: `Empresa/ProductosCompra/Index.vue` — insertar la barra**

Buscar:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <PurchaseProductsManager
```

Reemplazar por:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <ComprasTabs prefix="empresa" class="mb-5" />

        <PurchaseProductsManager
```

- [ ] **Step 7: Verificar**

Como admin-empresa, navega a Compras y haz clic en cada tab (`Compras · Proveedores · Productos`).
Cada tab carga su página y el tab activo se resalta en rojo. El ítem "Compras" del sidebar queda
resaltado en los 3.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Empresa/Compras/Index.vue resources/js/Pages/Empresa/Proveedores/Index.vue resources/js/Pages/Empresa/ProductosCompra/Index.vue
git commit -m "feat(compras): add tabs bar to empresa purchases pages"
```

---

### Task 5: Insertar la barra de tabs en las 3 páginas de Sucursal

**Files:**
- Modify: `resources/js/Pages/Sucursal/Compras/Index.vue`
- Modify: `resources/js/Pages/Sucursal/Proveedores/Index.vue`
- Modify: `resources/js/Pages/Sucursal/ProductosCompra/Index.vue`

- [ ] **Step 1: `Sucursal/Compras/Index.vue` — importar el componente**

Debajo de `import SucursalLayout from '@/Layouts/SucursalLayout.vue';`, añadir:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 2: `Sucursal/Compras/Index.vue` — insertar la barra**

Buscar:

```html
        </template>

        <div class="space-y-5">
```

Reemplazar por:

```html
        </template>

        <ComprasTabs prefix="sucursal" class="mb-5" />

        <div class="space-y-5">
```

- [ ] **Step 3: `Sucursal/Proveedores/Index.vue` — importar el componente**

Debajo de `import SucursalLayout from '@/Layouts/SucursalLayout.vue';`, añadir:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 4: `Sucursal/Proveedores/Index.vue` — insertar la barra**

Buscar:

```html
        </template>

        <div class="space-y-5">
```

Reemplazar por:

```html
        </template>

        <ComprasTabs prefix="sucursal" class="mb-5" />

        <div class="space-y-5">
```

- [ ] **Step 5: `Sucursal/ProductosCompra/Index.vue` — importar el componente**

Debajo de `import SucursalLayout from '@/Layouts/SucursalLayout.vue';`, añadir:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 6: `Sucursal/ProductosCompra/Index.vue` — insertar la barra**

Buscar:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <PurchaseProductsManager
```

Reemplazar por:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <ComprasTabs prefix="sucursal" class="mb-5" />

        <PurchaseProductsManager
```

- [ ] **Step 7: Verificar**

Como admin-sucursal, navega a Compras y haz clic en cada tab. Cada tab carga su página, el activo se
resalta, y el ítem "Compras" del sidebar queda resaltado en los 3.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Sucursal/Compras/Index.vue resources/js/Pages/Sucursal/Proveedores/Index.vue resources/js/Pages/Sucursal/ProductosCompra/Index.vue
git commit -m "feat(compras): add tabs bar to sucursal purchases pages"
```

---

### Task 6: Build y verificación manual completa

**Files:** ninguno (verificación)

- [ ] **Step 1: Build de producción**

Run: `vendor/bin/sail npm run build` (o `npm run build` si no usas Docker)
Expected: termina sin errores (build de Vite exitoso).

- [ ] **Step 2: Checklist manual — Empresa (`admin@eltoro.test`)**

- [ ] El sidebar muestra **un solo** ítem "Compras" (sin "Proveedores" ni "Productos de compra").
- [ ] Los 3 tabs (`Compras · Proveedores · Productos`) navegan correctamente.
- [ ] El tab activo se resalta en rojo; el ítem "Compras" del sidebar queda activo en los 3.
- [ ] Clic en un proveedor abre su página de detalle (con su back); el sidebar "Compras" sigue activo.

- [ ] **Step 3: Checklist manual — Sucursal (`sucursal@eltoro.test`)**

- [ ] El sidebar muestra **un solo** ítem "Compras".
- [ ] Los 3 tabs navegan y se resaltan correctamente.
- [ ] El ítem "Productos" (catálogo de venta, no de compra) sigue resaltándose normal en su sección.

- [ ] **Step 4: Checklist manual — Caja (`cajero@eltoro.test`, con compras habilitadas)**

- [ ] Sin cambios: solo se ve el ítem "Compras"; **no** aparece la barra de tabs.

- [ ] **Step 5: Commit final (si el build generó assets versionados que se commitean en este repo)**

```bash
git status   # revisar si hay assets de build a incluir según convención del repo
```

Si el repo no versiona los assets de `public/build`, no hay nada que commitear aquí.

---

## Self-Review (cobertura del spec)

- **Sidebar 3→1 (Empresa):** Task 2. ✓
- **Sidebar 3→1 (Sucursal):** Task 3. ✓
- **Componente ComprasTabs:** Task 1. ✓
- **Barra en las 6 páginas índice:** Tasks 4 y 5. ✓
- **`isActive` con `matches[]` sin romper `match`/`extraMatch`:** Tasks 2 y 3. ✓
- **Orden de tabs `Compras · Proveedores · Productos`, landing Compras:** Task 1 (array `tabs`). ✓
- **Detalle de proveedor navega aparte, sin barra de tabs:** no se toca `Proveedores/Show`; la barra solo se inserta en las páginas índice. Sidebar sigue activo por `matches`. ✓
- **Caja sin cambios:** ningún task toca `CajeroLayout.vue` ni páginas de Caja. ✓
- **Responsive (scroll horizontal):** Task 1 (`overflow-x-auto` + `shrink-0 whitespace-nowrap`). ✓
- **Rutas/controladores/datos sin cambios:** ningún task toca `routes/` ni controladores. ✓
