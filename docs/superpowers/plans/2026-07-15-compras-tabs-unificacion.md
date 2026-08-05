# Compras con tabs unificados — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una sola entrada "Compras" en el sidebar (Empresa y Sucursal) con 3 tabs de navegación (Compras | Productos de compra | Proveedores) sobre las rutas existentes, sin cambios de backend.

**Architecture:** Componente Vue de presentación `ComprasTabs.vue` (links Inertia a rutas existentes) montado en las 8 páginas del dominio; los dos layouts colapsan 3 entradas de nav en 1 y su `isActive` aprende a aceptar múltiples `extraMatch`. Cero cambios en rutas/controladores/tests PHP.

**Tech Stack:** Vue 3 `<script setup>`, Inertia v2 (`Link`, `usePage`), Ziggy (`route()`), Tailwind v3. Sin infraestructura de tests JS en el proyecto — la verificación es `npm run build` (errores de compilación de SFC) + QA manual.

**Spec:** `docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md`
**Rama:** `feat/compras-tabs-unificacion` (worktree: `/private/tmp/claude-501/-Users-sebas-Documents-version-2/e51ebe36-082f-48c1-9fba-d33483e9a303/scratchpad/compras-tabs`)

---

## Contexto para quien no conoce el codebase

- Es un SaaS Laravel + Inertia + Vue multi-tenant. Las rutas web llevan slug de tenant: `route('sucursal.compras.index', slug)`.
- El slug está en `page.props.auth.tenant_slug` (patrón usado en ambos layouts).
- Ziggy expone `route()` global en los SFC (no se importa). `route().current('patron*')` acepta glob; **ojo:** el `*` cruza puntos, `'sucursal.productos*'` también matchea `sucursal.productos-compra.index` — por eso la Task 3 corrige ese match.
- Hay dos roles/layouts espejo: `EmpresaLayout.vue` (prefijo de rutas `empresa.`) y `SucursalLayout.vue` (prefijo `sucursal.`). Las páginas del dominio existen por duplicado bajo `Pages/Empresa/**` y `Pages/Sucursal/**`.
- `npm run build` corre Vite y falla ante cualquier error de sintaxis/imports en los SFC. No hay vitest/jest: no escribas tests JS nuevos.

### Archivos que toca este plan

| Acción | Archivo | Responsabilidad |
|---|---|---|
| Crear | `resources/js/Components/Compras/ComprasTabs.vue` | Barra de 3 tabs de navegación |
| Modificar | `resources/js/Layouts/SucursalLayout.vue` | Nav 15→13, `isActive` multi-match, fix glob Productos |
| Modificar | `resources/js/Layouts/EmpresaLayout.vue` | Nav 13→11, `isActive` con extraMatch |
| Modificar | `resources/js/Pages/Empresa/Compras/Index.vue` | Montar tabs (active=compras) |
| Modificar | `resources/js/Pages/Sucursal/Compras/Index.vue` | Montar tabs (active=compras) |
| Modificar | `resources/js/Pages/Empresa/ProductosCompra/Index.vue` | Montar tabs (active=productos-compra) |
| Modificar | `resources/js/Pages/Sucursal/ProductosCompra/Index.vue` | Montar tabs (active=productos-compra) |
| Modificar | `resources/js/Pages/Empresa/Proveedores/Index.vue` | Montar tabs (active=proveedores) |
| Modificar | `resources/js/Pages/Sucursal/Proveedores/Index.vue` | Montar tabs (active=proveedores) |
| Modificar | `resources/js/Pages/Empresa/Proveedores/Show.vue` | Montar tabs (active=proveedores) |
| Modificar | `resources/js/Pages/Sucursal/Proveedores/Show.vue` | Montar tabs (active=proveedores) |
| Modificar | `docs/modulos/compras.md` | Documentar la navegación unificada |
| Modificar | `docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md` | Estado → Implementado |

---

### Task 1: Preparar el worktree

**Files:** ninguno (setup).

- [ ] **Step 1: Instalar dependencias JS**

```bash
cd "/private/tmp/claude-501/-Users-sebas-Documents-version-2/e51ebe36-082f-48c1-9fba-d33483e9a303/scratchpad/compras-tabs"
npm install
```

Expected: termina sin errores (crea `node_modules/`).

- [ ] **Step 2: Build baseline (verifica que main compila antes de tocar nada)**

Run: `npm run build`
Expected: `✓ built in …s` sin errores.

---

### Task 2: Componente `ComprasTabs.vue`

**Files:**
- Create: `resources/js/Components/Compras/ComprasTabs.vue`

- [ ] **Step 1: Crear el componente**

Contenido completo del archivo:

```vue
<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Barra de navegación del dominio de compras (spec 2026-07-15).
// Cada tab es un Link a la ruta existente de su sección; el prefijo de rol
// se infiere de la ruta actual (solo Empresa y Sucursal montan estas páginas).
const props = defineProps({
    active: {
        type: String,
        required: true,
        validator: (v) => ['compras', 'productos-compra', 'proveedores'].includes(v),
    },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const prefix = computed(() => (route().current() || '').startsWith('empresa.') ? 'empresa' : 'sucursal');

const tabs = computed(() => [
    { key: 'compras', label: 'Compras', href: route(`${prefix.value}.compras.index`, slug.value) },
    { key: 'productos-compra', label: 'Productos de compra', href: route(`${prefix.value}.productos-compra.index`, slug.value) },
    { key: 'proveedores', label: 'Proveedores', href: route(`${prefix.value}.proveedores.index`, slug.value) },
]);
</script>

<template>
    <nav class="mb-5 flex gap-6 overflow-x-auto border-b border-gray-200" aria-label="Secciones de compras">
        <Link v-for="tab in tabs" :key="tab.key" :href="tab.href"
            class="-mb-px whitespace-nowrap border-b-2 pb-2.5 text-sm font-semibold transition"
            :class="tab.key === props.active
                ? 'border-gray-900 text-gray-900'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'">
            {{ tab.label }}
        </Link>
    </nav>
</template>
```

- [ ] **Step 2: Verificar que compila**

Run: `npm run build`
Expected: `✓ built` sin errores. (El componente aún no se usa; Vite igual lo type-parsea al importarse en tasks siguientes — este build solo confirma que no rompimos nada.)

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Compras/ComprasTabs.vue
git commit -m "feat(compras): componente ComprasTabs de navegación unificada"
```

---

### Task 3: `SucursalLayout.vue` — colapsar nav y multi-match

**Files:**
- Modify: `resources/js/Layouts/SucursalLayout.vue:20-32` (nav links) y `:44-50` (`isActive`)

- [ ] **Step 1: Corregir el glob de Productos (evita doble resaltado con productos-compra)**

Reemplazar (línea ~20):

```js
    { label: 'Productos', route: 'sucursal.productos.index', match: 'sucursal.productos', extraMatch: 'sucursal.categorias', icon: 'productos' },
```

por:

```js
    { label: 'Productos', route: 'sucursal.productos.index', match: 'sucursal.productos.', extraMatch: 'sucursal.categorias', icon: 'productos' },
```

(El punto final hace que el glob `sucursal.productos.*` ya NO matchee `sucursal.productos-compra.*`.)

- [ ] **Step 2: Colapsar las 3 entradas en una**

Reemplazar (líneas ~27-29):

```js
    { label: 'Proveedores', route: 'sucursal.proveedores.index', match: 'sucursal.proveedores', icon: 'proveedores' },
    { label: 'Compras', route: 'sucursal.compras.index', match: 'sucursal.compras', icon: 'compras' },
    { label: 'Productos de compra', route: 'sucursal.productos-compra.index', match: 'sucursal.productos-compra', icon: 'productoscompra' },
```

por:

```js
    // Compras agrupa Compras + Productos de compra + Proveedores (tabs en página; spec 2026-07-15).
    { label: 'Compras', route: 'sucursal.compras.index', match: 'sucursal.compras', extraMatch: ['sucursal.productos-compra', 'sucursal.proveedores'], icon: 'compras' },
```

- [ ] **Step 3: `isActive` acepta `extraMatch` como string o arreglo**

Reemplazar (líneas ~44-50):

```js
const isActive = (link) => {
    if (link.match) {
        if (route().current(link.match + '*') || route().current(link.route)) return true;
        if (link.extraMatch && route().current(link.extraMatch + '*')) return true;
    }
    return route().current(link.route);
};
```

por:

```js
const isActive = (link) => {
    if (link.match) {
        if (route().current(link.match + '*') || route().current(link.route)) return true;
        const extras = Array.isArray(link.extraMatch) ? link.extraMatch : (link.extraMatch ? [link.extraMatch] : []);
        if (extras.some((m) => route().current(m + '*'))) return true;
    }
    return route().current(link.route);
};
```

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/SucursalLayout.vue
git commit -m "feat(compras): sidebar sucursal unifica compras/productos-compra/proveedores"
```

---

### Task 4: `EmpresaLayout.vue` — colapsar nav y soportar extraMatch

**Files:**
- Modify: `resources/js/Layouts/EmpresaLayout.vue:19-21` (nav links) y `:34-37` (`isActive`)

- [ ] **Step 1: Colapsar las 3 entradas en una**

Reemplazar (líneas ~19-21):

```js
    { label: 'Proveedores', route: 'empresa.proveedores.index', match: 'empresa.proveedores', icon: 'proveedores' },
    { label: 'Compras', route: 'empresa.compras.index', match: 'empresa.compras', icon: 'compras' },
    { label: 'Productos de compra', route: 'empresa.productos-compra.index', match: 'empresa.productos-compra', icon: 'proveedores' },
```

por:

```js
    // Compras agrupa Compras + Productos de compra + Proveedores (tabs en página; spec 2026-07-15).
    { label: 'Compras', route: 'empresa.compras.index', match: 'empresa.compras', extraMatch: ['empresa.productos-compra', 'empresa.proveedores'], icon: 'compras' },
```

- [ ] **Step 2: `isActive` con soporte de `extraMatch` (hoy este layout no lo tiene)**

Reemplazar (líneas ~34-37):

```js
const isActive = (link) => {
    if (link.match) return route().current(link.match + '*') || route().current(link.route);
    return route().current(link.route);
};
```

por:

```js
const isActive = (link) => {
    if (link.match) {
        if (route().current(link.match + '*') || route().current(link.route)) return true;
        const extras = Array.isArray(link.extraMatch) ? link.extraMatch : (link.extraMatch ? [link.extraMatch] : []);
        if (extras.some((m) => route().current(m + '*'))) return true;
    }
    return route().current(link.route);
};
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/EmpresaLayout.vue
git commit -m "feat(compras): sidebar empresa unifica compras/productos-compra/proveedores"
```

---

### Task 5: Montar tabs en Compras (2 páginas)

**Files:**
- Modify: `resources/js/Pages/Empresa/Compras/Index.vue` (import ~línea 2; template ~línea 102)
- Modify: `resources/js/Pages/Sucursal/Compras/Index.vue` (import ~línea 2; template ~línea 90)

En ambas páginas el patrón es idéntico: import + `<ComprasTabs>` como primer elemento del slot default (antes del `<div class="space-y-5">`).

- [ ] **Step 1: `Empresa/Compras/Index.vue` — agregar import**

Después de la línea `import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';` agregar:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 2: `Empresa/Compras/Index.vue` — montar el componente**

Reemplazar:

```html
        </template>

        <div class="space-y-5">
            <!-- KPIs -->
```

por:

```html
        </template>

        <ComprasTabs active="compras" />

        <div class="space-y-5">
            <!-- KPIs -->
```

- [ ] **Step 3: `Sucursal/Compras/Index.vue` — agregar import**

Después de la línea `import SucursalLayout from '@/Layouts/SucursalLayout.vue';` agregar:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 4: `Sucursal/Compras/Index.vue` — montar el componente**

Reemplazar:

```html
        </template>

        <div class="space-y-5">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
```

por:

```html
        </template>

        <ComprasTabs active="compras" />

        <div class="space-y-5">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
```

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Empresa/Compras/Index.vue resources/js/Pages/Sucursal/Compras/Index.vue
git commit -m "feat(compras): tabs de navegación en index de compras (empresa y sucursal)"
```

---

### Task 6: Montar tabs en Productos de compra (2 páginas)

**Files:**
- Modify: `resources/js/Pages/Empresa/ProductosCompra/Index.vue`
- Modify: `resources/js/Pages/Sucursal/ProductosCompra/Index.vue`

El segmented Productos/Categorías vive dentro de `PurchaseProductsManager` y NO se toca.

- [ ] **Step 1: `Empresa/ProductosCompra/Index.vue` — agregar import**

Después de `import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';` agregar:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

- [ ] **Step 2: `Empresa/ProductosCompra/Index.vue` — montar el componente**

Reemplazar:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <PurchaseProductsManager
```

por:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <ComprasTabs active="productos-compra" />

        <PurchaseProductsManager
```

- [ ] **Step 3: `Sucursal/ProductosCompra/Index.vue` — mismo par de cambios**

Import después de `import SucursalLayout from '@/Layouts/SucursalLayout.vue';`:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

Y reemplazar:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <PurchaseProductsManager
```

por:

```html
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <ComprasTabs active="productos-compra" />

        <PurchaseProductsManager
```

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Empresa/ProductosCompra/Index.vue resources/js/Pages/Sucursal/ProductosCompra/Index.vue
git commit -m "feat(compras): tabs de navegación en productos de compra (empresa y sucursal)"
```

---

### Task 7: Montar tabs en Proveedores (4 páginas: Index + Show × 2 roles)

**Files:**
- Modify: `resources/js/Pages/Empresa/Proveedores/Index.vue`
- Modify: `resources/js/Pages/Sucursal/Proveedores/Index.vue`
- Modify: `resources/js/Pages/Empresa/Proveedores/Show.vue`
- Modify: `resources/js/Pages/Sucursal/Proveedores/Show.vue`

- [ ] **Step 1: `Empresa/Proveedores/Index.vue`**

Import después de `import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';`:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

Reemplazar:

```html
        </template>

        <div class="space-y-5">
            <!-- KPIs -->
```

por:

```html
        </template>

        <ComprasTabs active="proveedores" />

        <div class="space-y-5">
            <!-- KPIs -->
```

- [ ] **Step 2: `Sucursal/Proveedores/Index.vue`**

Import después de `import SucursalLayout from '@/Layouts/SucursalLayout.vue';`:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

Reemplazar:

```html
        </template>

        <div class="space-y-5">
            <div v-if="!canManage" class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
```

por:

```html
        </template>

        <ComprasTabs active="proveedores" />

        <div class="space-y-5">
            <div v-if="!canManage" class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
```

- [ ] **Step 3: `Empresa/Proveedores/Show.vue`**

Import después de `import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';`:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

Reemplazar:

```html
        </template>

        <ProviderDetail :provider="provider" :seed="seed" :purchase-products="purchaseProducts" route-prefix="empresa" :can-register-payment="true" />
```

por:

```html
        </template>

        <ComprasTabs active="proveedores" />

        <ProviderDetail :provider="provider" :seed="seed" :purchase-products="purchaseProducts" route-prefix="empresa" :can-register-payment="true" />
```

- [ ] **Step 4: `Sucursal/Proveedores/Show.vue`**

Import después de `import SucursalLayout from '@/Layouts/SucursalLayout.vue';`:

```js
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
```

Reemplazar:

```html
        </template>

        <ProviderDetail :provider="provider" :seed="seed" :purchase-products="purchaseProducts" route-prefix="sucursal" :can-register-payment="true" />
```

por:

```html
        </template>

        <ComprasTabs active="proveedores" />

        <ProviderDetail :provider="provider" :seed="seed" :purchase-products="purchaseProducts" route-prefix="sucursal" :can-register-payment="true" />
```

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Empresa/Proveedores/ resources/js/Pages/Sucursal/Proveedores/
git commit -m "feat(compras): tabs de navegación en proveedores index y detalle (ambos roles)"
```

---

### Task 8: Documentación

**Files:**
- Modify: `docs/modulos/compras.md` (después del heading `## UI`, línea ~188)
- Modify: `docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md` (header `Estado:`)

- [ ] **Step 1: Documentar la navegación en el doc vivo**

Inmediatamente después de la línea `## UI` (y antes de `### /{tenant}/empresa/proveedores`) insertar:

```markdown
### Navegación unificada (2026-07-15)

El sidebar tiene **una sola entrada "Compras"** (Empresa y Sucursal). Dentro, el
componente `Components/Compras/ComprasTabs.vue` muestra 3 tabs de navegación
(Compras | Productos de compra | Proveedores); cada tab es un `<Link>` de
Inertia a la ruta existente de su sección — las rutas y controladores no
cambiaron. El detalle de proveedor conserva la barra con "Proveedores" activo.
El segmented Productos/Categorías sigue viviendo dentro de *Productos de
compra*. Spec: `docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md`.
```

- [ ] **Step 2: Actualizar el Estado del spec**

En `docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md` reemplazar:

```markdown
**Estado:** Aprobado — pendiente de plan
```

por:

```markdown
**Estado:** Implementado (2026-07-15) — ver docs/modulos/compras.md § Navegación unificada
```

- [ ] **Step 3: Commit**

```bash
git add docs/modulos/compras.md docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md
git commit -m "docs(compras): navegación unificada con tabs; spec a Implementado"
```

---

### Task 9: Verificación final y PR

- [ ] **Step 1: Build final**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 2: Push y PR**

```bash
git push -u origin feat/compras-tabs-unificacion
gh pr create --base main --head feat/compras-tabs-unificacion \
  --title "feat(compras): entrada única con tabs (Compras | Productos de compra | Proveedores)" \
  --body "Implementa el spec docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md. Solo frontend: sidebar 15→13 (sucursal) y 13→11 (empresa), tabs de navegación sobre rutas existentes, cero cambios de backend. Incluye fix del glob 'sucursal.productos' que doble-resaltaba con productos-compra."
```

- [ ] **Step 3: QA manual (en el entorno del usuario, con `composer run dev` o Sail)**

Checklist para el usuario (o quien tenga el entorno corriendo):

1. Login `sucursal@eltoro.test` / `password` (tenant `el-toro`): el sidebar ya no muestra "Proveedores" ni "Productos de compra"; "Compras" abre el listado con los 3 tabs; navegar entre tabs mantiene la entrada resaltada; entrar al detalle de un proveedor conserva la barra; el segmented Productos/Categorías sigue funcionando; la entrada "Productos" NO se resalta al estar en Productos de compra.
2. Login `admin@eltoro.test` / `password`: mismas verificaciones en `/empresa`.
3. Deep links viejos siguen vivos: `/el-toro/sucursal/proveedores` y `/el-toro/empresa/productos-compra` cargan con su tab activo correcto.
